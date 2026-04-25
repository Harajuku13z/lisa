<?php

namespace App\Services\AI;

use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use App\Services\AI\Agents\AppointmentAgent;
use App\Services\AI\Agents\ChecklistAgent;
use App\Services\AI\Agents\NoteAgent;
use App\Services\AI\Agents\PatientAgent;
use App\Services\AI\Agents\ReminderAgent;
use App\Services\AI\Agents\RoomAgent;
use App\Services\AI\Agents\VitalSignsAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LisaOrchestrator
{
    private User   $user;
    private string $date;
    private array  $agents;

    /**
     * Strict execution order — actions later in the list depend on IDs
     * created by earlier ones. Anything not listed runs after.
     */
    private const INTENT_ORDER = [
        'create_room'           => 10,
        'find_room'             => 15,
        'create_patient'        => 20,
        'find_patient'          => 25,
        'link_patient_to_room'  => 30,
        'add_vital_sign'        => 40,
        'create_appointment'    => 50,
        'create_checklist_item' => 60,
        'toggle_checklist_item' => 65,
        'create_reminder'       => 70,
        'create_note'           => 80,
    ];

    /** Preloaded entities (set when called from PatientView/RoomView) */
    private ?Patient $preloadedPatient = null;
    private ?Room    $preloadedRoom    = null;

    public function __construct(User $user, string $date)
    {
        $this->user = $user;
        $this->date = $date;

        $this->agents = [
            'RoomAgent'        => new RoomAgent($user, $date),
            'PatientAgent'     => new PatientAgent($user, $date),
            'ChecklistAgent'   => new ChecklistAgent($user, $date),
            'VitalSignsAgent'  => new VitalSignsAgent($user, $date),
            'AppointmentAgent' => new AppointmentAgent($user, $date),
            'ReminderAgent'    => new ReminderAgent($user, $date),
            'NoteAgent'        => new NoteAgent($user, $date),
        ];
    }

    // ─────────────────────────────────────────────
    // Preloading: when the iOS client calls Lisa from inside a patient/room
    // view, it can attach the patient_id/room_id so the orchestrator can
    // bind every action to that patient without relying on name resolution.
    // ─────────────────────────────────────────────
    public function preloadPatient(?int $patientId): void
    {
        if (!$patientId) return;
        $patient = Patient::with('assignedRoom.day')->find($patientId);
        if (!$patient) return;

        // Authorize: must belong to current user
        $day = $patient->assignedRoom?->day;
        if (!$day || $day->user_id !== $this->user->id) return;

        $this->preloadedPatient = $patient;
        if ($patient->assignedRoom) {
            $this->preloadedRoom = $patient->assignedRoom;
        }
    }

    public function preloadRoom(?int $roomId): void
    {
        if (!$roomId) return;
        $room = Room::with('day')->find($roomId);
        if (!$room || !$room->day || $room->day->user_id !== $this->user->id) return;
        $this->preloadedRoom = $room;
    }

    // ─────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────
    public function handle(string $message, string $source = 'text'): array
    {
        // 1. Ask OpenAI for a structured action plan
        $parsed = $this->parseWithAI($message);

        if (!$parsed) {
            return $this->stableResponse(
                false,
                "Lisa n'a pas pu analyser votre message. Réessayez.",
                $message,
                [],
                ['ai' => ['Réponse IA invalide']]
            );
        }

        // 2. Confirmation request — return immediately, do not execute
        if (!empty($parsed['needs_confirmation']) && $parsed['needs_confirmation'] === true) {
            $question = $parsed['confirmation_question'] ?? 'Pouvez-vous préciser votre demande ?';
            return [
                'success'               => false,
                'needs_confirmation'    => true,
                'confirmation_question' => $question,
                'summary'               => $question,
                'message'               => $question,
                'actions'               => [],
                'errors'                => [],
                'data'                  => null,
                'original_message'      => $message,
                'missing_information'   => $parsed['missing_information'] ?? [],
            ];
        }

        // 3. Sort actions by dependency order + filet de sécurité regex
        $detectedActions = $parsed['detected_actions'] ?? [];
        $detectedActions = $this->mergeRegexFallbacks($message, $detectedActions);
        $detectedActions = $this->sortActions($detectedActions);

        Log::info('LisaOrchestrator plan', [
            'message' => $message,
            'actions' => $detectedActions,
        ]);

        // 4. Execute everything inside a single DB transaction
        $context = new ExecutionContext($this->user, $this->date);

        // Preload patient/room into context so agents resolve them instantly
        if ($this->preloadedRoom)    { $context->rememberRoom($this->preloadedRoom); }
        if ($this->preloadedPatient) { $context->rememberPatient($this->preloadedPatient); }

        try {
            DB::transaction(function () use ($detectedActions, $context) {
                foreach ($detectedActions as $action) {
                    $this->executeAction($action, $context);
                }
            });
        } catch (\Throwable $e) {
            Log::error('LisaOrchestrator transaction failure', [
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'message' => $message,
            ]);
            return $this->stableResponse(
                false,
                "Une erreur est survenue, aucune donnée n'a été enregistrée.",
                $message,
                [],
                ['transaction' => [$e->getMessage()]]
            );
        }

        // 5. Build human-readable summary
        $summary = $this->buildSummary($context);
        $hasResults = !empty($context->results);
        $success    = empty($context->errors) && $hasResults;

        // Convert error array → keyed errors object for stable contract
        $errorsObject = [];
        foreach ($context->errors as $i => $err) {
            $errorsObject["error_{$i}"] = [$err];
        }

        return [
            'success'             => $success,
            'summary'             => $summary,
            'message'             => $summary,
            'actions'             => $context->results,
            'errors'              => $context->errors,
            'data'                => $hasResults ? ['results' => $context->results] : null,
            'original_message'    => $message,
            'missing_information' => $parsed['missing_information'] ?? [],
        ];
    }

    // ─────────────────────────────────────────────
    // Run a single action through its agent + capture result
    // ─────────────────────────────────────────────
    private function executeAction(array $action, ExecutionContext $context): void
    {
        $agentName = $action['agent'] ?? null;

        if (!$agentName || !isset($this->agents[$agentName])) {
            $context->addError("Agent inconnu : " . ($agentName ?? 'null'));
            return;
        }

        $agent = $this->agents[$agentName];

        try {
            // Agents that accept an ExecutionContext use it; others fall back to their old signature
            if (method_exists($agent, 'handleWithContext')) {
                $result = $agent->handleWithContext($action, $context);
            } else {
                $result = $agent->handle($action);
            }

            if (!is_array($result)) {
                $context->addError("Réponse invalide de {$agentName}");
                return;
            }

            if (($result['success'] ?? false) === true) {
                $context->addResult([
                    'agent'   => $agentName,
                    'intent'  => $action['intent'] ?? null,
                    'message' => $result['message'] ?? null,
                ]);
            } else {
                $context->addError($result['error'] ?? "Échec de {$agentName}");
            }
        } catch (\Throwable $e) {
            Log::error("LisaOrchestrator agent error", [
                'agent'  => $agentName,
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
            $context->addError("Erreur sur {$agentName} : {$e->getMessage()}");
        }
    }

    // ─────────────────────────────────────────────
    // Filet de sécurité : si l'IA oublie une constante / un rendez-vous,
    // on la rajoute via regex sur le message original.
    // ─────────────────────────────────────────────
    private function mergeRegexFallbacks(string $message, array $actions): array
    {
        $patientName = $this->preloadedPatient?->name;
        $roomNumber  = $this->preloadedRoom?->number;
        foreach ($actions as $a) {
            $patientName ??= $a['data']['patient_name'] ?? null;
            $roomNumber  ??= $a['data']['room_number']  ?? null;
        }

        $hasVital = false;
        $hasAppointment = false;
        foreach ($actions as $a) {
            if (($a['intent'] ?? '') === 'add_vital_sign')     $hasVital = true;
            if (($a['intent'] ?? '') === 'create_appointment') $hasAppointment = true;
        }

        // Température : "30°", "30 degrés", "température 38.5"
        if (!$hasVital && preg_match('/(?:température\s+(?:à|de)?\s*|temp[ée]rature[\s:]*|fi[èe]vre[\s:]*)?(\d{2,3}(?:[.,]\d)?)\s*(?:°|degr[ée]s?)/iu', $message, $m)) {
            $value = str_replace(',', '.', $m[1]);
            if ((float) $value >= 25 && (float) $value <= 45) {
                $actions[] = [
                    'agent'  => 'VitalSignsAgent',
                    'intent' => 'add_vital_sign',
                    'data'   => [
                        'patient_name' => $patientName,
                        'room_number'  => $roomNumber,
                        'type'         => 'temperature',
                        'value'        => $value,
                    ],
                ];
            }
        }

        // Tension : "tension 12/8", "TA 120/80"
        if (preg_match('/(?:tension|TA|pression art[ée]rielle)[\s:]*(\d{2,3}\/\d{1,3})/iu', $message, $m)) {
            $alreadyHas = false;
            foreach ($actions as $a) {
                if (($a['intent'] ?? '') === 'add_vital_sign' && ($a['data']['type'] ?? '') === 'blood_pressure') $alreadyHas = true;
            }
            if (!$alreadyHas) {
                $actions[] = [
                    'agent'  => 'VitalSignsAgent',
                    'intent' => 'add_vital_sign',
                    'data'   => [
                        'patient_name' => $patientName,
                        'room_number'  => $roomNumber,
                        'type'         => 'blood_pressure',
                        'value'        => $m[1],
                    ],
                ];
            }
        }

        // Saturation : "saturation 96", "SpO2 98"
        if (preg_match('/(?:saturation|SpO2|sat)[\s:]*(\d{2,3}(?:[.,]\d)?)/iu', $message, $m)) {
            $alreadyHas = false;
            foreach ($actions as $a) {
                if (($a['intent'] ?? '') === 'add_vital_sign' && ($a['data']['type'] ?? '') === 'oxygen_saturation') $alreadyHas = true;
            }
            if (!$alreadyHas) {
                $actions[] = [
                    'agent'  => 'VitalSignsAgent',
                    'intent' => 'add_vital_sign',
                    'data'   => [
                        'patient_name' => $patientName,
                        'room_number'  => $roomNumber,
                        'type'         => 'oxygen_saturation',
                        'value'        => str_replace(',', '.', $m[1]),
                    ],
                ];
            }
        }

        // Pouls : "pouls 72", "fréquence cardiaque 80"
        if (preg_match('/(?:pouls|fr[ée]quence\s+cardiaque|FC|bpm)[\s:]*(\d{2,3})/iu', $message, $m)) {
            $alreadyHas = false;
            foreach ($actions as $a) {
                if (($a['intent'] ?? '') === 'add_vital_sign' && ($a['data']['type'] ?? '') === 'heart_rate') $alreadyHas = true;
            }
            if (!$alreadyHas) {
                $actions[] = [
                    'agent'  => 'VitalSignsAgent',
                    'intent' => 'add_vital_sign',
                    'data'   => [
                        'patient_name' => $patientName,
                        'room_number'  => $roomNumber,
                        'type'         => 'heart_rate',
                        'value'        => $m[1],
                    ],
                ];
            }
        }

        return $actions;
    }

    // ─────────────────────────────────────────────
    // Sort actions so dependencies are satisfied
    // ─────────────────────────────────────────────
    private function sortActions(array $actions): array
    {
        usort($actions, function ($a, $b) {
            $pa = self::INTENT_ORDER[$a['intent'] ?? ''] ?? 999;
            $pb = self::INTENT_ORDER[$b['intent'] ?? ''] ?? 999;
            return $pa <=> $pb;
        });
        return $actions;
    }

    // ─────────────────────────────────────────────
    // OpenAI call — returns structured JSON or null
    // ─────────────────────────────────────────────
    private function parseWithAI(string $message): ?array
    {
        $contextHint = '';
        if ($this->preloadedPatient) {
            $contextHint .= "\nContexte actif : tu es DÉJÀ dans le dossier du patient « {$this->preloadedPatient->name} »";
            if ($this->preloadedRoom) {
                $contextHint .= " (chambre {$this->preloadedRoom->number})";
            }
            $contextHint .= ". Toute action s'applique à ce patient. Tu n'as pas besoin de create_room ni create_patient.";
        } elseif ($this->preloadedRoom) {
            $contextHint = "\nContexte actif : tu es DÉJÀ dans la chambre {$this->preloadedRoom->number}.";
        }

        $systemPrompt = <<<PROMPT
Tu es Lisa, un orchestrateur IA pour une application infirmière.{$contextHint}
Tu comprends les messages naturels de l'utilisatrice et tu les transformes en actions structurées.

Règles absolues :
- Ne jamais inventer d'informations non présentes dans le message
- Ne jamais faire de diagnostic médical
- Ne jamais prescrire de médicaments
- Si une information est absente, mettre null
- Si le patient ou la chambre n'est pas clairement identifié, demander confirmation

Normalisation OBLIGATOIRE :
- "numéro une", "chambre une", "1ère" => "1"
- "numéro deux", "chambre deux"        => "2"
- "30°", "30 degrés"                   => value="30" type="temperature"
- "tension 12/8"                       => value="12/8" type="blood_pressure"
- "15h", "15 heures"                   => "15h00"
- "15h30"                              => "15h30"

Agents disponibles : RoomAgent, PatientAgent, ChecklistAgent, VitalSignsAgent, AppointmentAgent, ReminderAgent, NoteAgent

IMPORTANT : pour une phrase qui mentionne une chambre + un patient, tu DOIS produire :
1. create_room (RoomAgent) avec room_number
2. create_patient (PatientAgent) avec patient_name + room_number
3. ENSUITE seulement les autres actions (constantes, rendez-vous, …)

Retourne UNIQUEMENT un JSON valide avec cette structure :
{
  "original_message": "...",
  "detected_actions": [
    {
      "agent": "RoomAgent",
      "intent": "create_room",
      "data": { "room_number": "1" }
    },
    {
      "agent": "PatientAgent",
      "intent": "create_patient",
      "data": { "patient_name": "Robert", "room_number": "1" }
    },
    {
      "agent": "VitalSignsAgent",
      "intent": "add_vital_sign",
      "data": { "patient_name": "Robert", "room_number": "1", "type": "temperature", "value": "30", "unit": "°C", "time": null }
    },
    {
      "agent": "AppointmentAgent",
      "intent": "create_appointment",
      "data": { "patient_name": "Robert", "room_number": "1", "title": "Voir le médecin", "due_label": "15h00", "priority": "high" }
    }
  ],
  "missing_information": [],
  "needs_confirmation": false,
  "confirmation_question": null
}

Intents disponibles par agent :
- RoomAgent       : create_room, find_room
- PatientAgent    : create_patient, find_patient
- VitalSignsAgent : add_vital_sign (types: temperature, blood_pressure, heart_rate, oxygen_saturation, respiratory_rate, blood_glucose, pain_level, weight)
- ChecklistAgent  : create_checklist_item, toggle_checklist_item
- AppointmentAgent: create_appointment (toujours avec due_label)
- ReminderAgent   : create_reminder
- NoteAgent       : create_note (pour les observations libres sans action claire)

Toujours inclure `room_number` dans `data` quand c'est connu, même pour les agents secondaires (constantes, rendez-vous).

Date du jour : {$this->date}
PROMPT;

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => config('services.openai.model', 'gpt-4o-mini'),
                    'messages'        => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $message],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens'      => 2000,
                    'temperature'     => 0.1,
                ]);

            $content = $response->json('choices.0.message.content');
            $parsed  = json_decode($content, true);

            if (!$parsed || !isset($parsed['detected_actions'])) {
                Log::warning('LisaOrchestrator: invalid AI response', ['content' => $content]);
                return null;
            }

            return $parsed;
        } catch (\Exception $e) {
            Log::error('LisaOrchestrator: OpenAI error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ─────────────────────────────────────────────
    // Build a human-friendly summary from results
    // ─────────────────────────────────────────────
    private function buildSummary(ExecutionContext $context): string
    {
        $counts = [
            'chambres'    => 0,
            'patients'    => 0,
            'constantes'  => 0,
            'rendez-vous' => 0,
            'tâches'      => 0,
            'rappels'     => 0,
            'notes'       => 0,
        ];

        foreach ($context->results as $result) {
            match ($result['agent'] ?? '') {
                'RoomAgent'        => $counts['chambres']++,
                'PatientAgent'     => $counts['patients']++,
                'VitalSignsAgent'  => $counts['constantes']++,
                'AppointmentAgent' => $counts['rendez-vous']++,
                'ChecklistAgent'   => $counts['tâches']++,
                'ReminderAgent'    => $counts['rappels']++,
                'NoteAgent'        => $counts['notes']++,
                default            => null,
            };
        }

        $parts = [];
        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$label}";
            }
        }

        if (empty($parts) && empty($context->errors)) {
            return 'Aucune action effectuée';
        }

        if (empty($parts) && !empty($context->errors)) {
            return $context->errors[0];
        }

        $summary = 'Lisa a enregistré : ' . implode(', ', $parts);

        if (!empty($context->errors)) {
            $summary .= ' (' . count($context->errors) . ' avertissement' . (count($context->errors) > 1 ? 's' : '') . ')';
        }

        return $summary;
    }

    // ─────────────────────────────────────────────
    // Stable error response
    // ─────────────────────────────────────────────
    private function stableResponse(bool $success, string $message, string $original, array $actions = [], array $errors = []): array
    {
        return [
            'success'             => $success,
            'summary'             => $message,
            'message'             => $message,
            'actions'             => $actions,
            'errors'              => array_values(array_map(fn ($e) => is_array($e) ? ($e[0] ?? '') : $e, $errors)),
            'data'                => null,
            'original_message'    => $original,
            'missing_information' => [],
        ];
    }
}
