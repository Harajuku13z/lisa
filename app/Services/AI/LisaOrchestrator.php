<?php

namespace App\Services\AI;

use App\Models\User;
use App\Services\AI\Agents\AppointmentAgent;
use App\Services\AI\Agents\ChecklistAgent;
use App\Services\AI\Agents\NoteAgent;
use App\Services\AI\Agents\PatientAgent;
use App\Services\AI\Agents\ReminderAgent;
use App\Services\AI\Agents\RoomAgent;
use App\Services\AI\Agents\VitalSignsAgent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LisaOrchestrator
{
    private User   $user;
    private string $date;
    private array  $agents;

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
    // Entry point
    // ─────────────────────────────────────────────
    public function handle(string $message, string $source = 'text'): array
    {
        // 1. Ask OpenAI to parse the message into structured actions
        $parsed = $this->parseWithAI($message);

        if (!$parsed) {
            return $this->errorResponse('Lisa n\'a pas pu analyser votre message. Réessayez.', $message);
        }

        // 2. If a confirmation is needed, return the question immediately without executing
        if (!empty($parsed['needs_confirmation']) && $parsed['needs_confirmation'] === true) {
            return [
                'success'               => false,
                'needs_confirmation'    => true,
                'confirmation_question' => $parsed['confirmation_question'] ?? 'Pouvez-vous préciser votre demande ?',
                'summary'               => $parsed['confirmation_question'] ?? 'Information manquante',
                'actions'               => [],
                'original_message'      => $message,
            ];
        }

        // 3. Execute each detected action through the right agent
        $executedActions  = [];
        $errors           = [];
        $detectedActions  = $parsed['detected_actions'] ?? [];

        foreach ($detectedActions as $action) {
            $agentName = $action['agent'] ?? null;

            if (!$agentName || !isset($this->agents[$agentName])) {
                $errors[] = "Agent inconnu : {$agentName}";
                continue;
            }

            try {
                $result = $this->agents[$agentName]->handle($action);
                $executedActions[] = array_merge($action, ['result' => $result]);
            } catch (\Throwable $e) {
                Log::error("LisaOrchestrator agent error", [
                    'agent'  => $agentName,
                    'action' => $action,
                    'error'  => $e->getMessage(),
                ]);
                $errors[] = "Erreur sur {$agentName} : {$e->getMessage()}";
            }
        }

        // 4. Build human-readable summary
        $summary = $this->buildSummary($executedActions, $errors);

        return [
            'success'          => empty($errors),
            'summary'          => $summary,
            'actions'          => $executedActions,
            'errors'           => $errors,
            'original_message' => $message,
            'missing_information' => $parsed['missing_information'] ?? [],
        ];
    }

    // ─────────────────────────────────────────────
    // OpenAI call — returns structured JSON or null
    // ─────────────────────────────────────────────
    private function parseWithAI(string $message): ?array
    {
        $systemPrompt = <<<PROMPT
Tu es Lisa, un orchestrateur IA pour une application infirmière.
Tu comprends les messages naturels de l'utilisatrice et tu les transformes en actions structurées.

Règles absolues :
- Ne jamais inventer d'informations non présentes dans le message
- Ne jamais faire de diagnostic médical
- Ne jamais prescrire de médicaments
- Si une information est absente, mettre null
- Si le patient ou la chambre n'est pas clairement identifié, demander confirmation

Agents disponibles : RoomAgent, PatientAgent, ChecklistAgent, VitalSignsAgent, AppointmentAgent, ReminderAgent, NoteAgent

Retourne UNIQUEMENT un JSON valide avec cette structure :
{
  "original_message": "...",
  "detected_actions": [
    {
      "agent": "RoomAgent",
      "intent": "create_room",
      "data": { "room_number": "12", "date": "YYYY-MM-DD" }
    },
    {
      "agent": "PatientAgent",
      "intent": "create_patient",
      "data": { "patient_name": "Mme Dupont", "room_number": "12" }
    },
    {
      "agent": "VitalSignsAgent",
      "intent": "add_vital_sign",
      "data": { "patient_name": "Mme Dupont", "type": "blood_pressure", "value": "12/8", "unit": null, "time": null }
    },
    {
      "agent": "ChecklistAgent",
      "intent": "create_checklist_item",
      "data": { "patient_name": "Mme Dupont", "room_number": null, "task": "Pansement fait", "due_label": null, "priority": "normal", "status": "done" }
    },
    {
      "agent": "ReminderAgent",
      "intent": "create_reminder",
      "data": { "title": "Vérifier la perfusion", "remind_at": "16h00", "patient_name": null, "room_number": null }
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
    // Build a human-friendly summary
    // ─────────────────────────────────────────────
    private function buildSummary(array $actions, array $errors): string
    {
        $parts = [];

        $counts = [
            'chambres'   => 0,
            'patients'   => 0,
            'constantes' => 0,
            'tâches'     => 0,
            'rappels'    => 0,
            'notes'      => 0,
            'rendez-vous'=> 0,
        ];

        foreach ($actions as $action) {
            $result = $action['result'] ?? [];
            if (($result['success'] ?? false) === false) continue;

            match ($action['agent'] ?? '') {
                'RoomAgent'        => $counts['chambres']++,
                'PatientAgent'     => $counts['patients']++,
                'VitalSignsAgent'  => $counts['constantes']++,
                'ChecklistAgent'   => $counts['tâches']++,
                'ReminderAgent'    => $counts['rappels']++,
                'NoteAgent'        => $counts['notes']++,
                'AppointmentAgent' => $counts['rendez-vous']++,
                default            => null,
            };
        }

        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $parts[] = "{$count} {$label}";
            }
        }

        if (empty($parts) && empty($errors)) {
            return 'Aucune action effectuée';
        }

        $summary = 'Lisa a enregistré : ' . implode(', ', $parts);

        if (!empty($errors)) {
            $summary .= ' (' . count($errors) . ' erreur(s))';
        }

        return $summary;
    }

    private function errorResponse(string $message, string $original): array
    {
        return [
            'success'          => false,
            'summary'          => $message,
            'actions'          => [],
            'errors'           => [$message],
            'original_message' => $original,
            'missing_information' => [],
        ];
    }
}
