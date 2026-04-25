<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RoomVoiceController extends Controller {

    public function process(Request $request, Room $room) {
        if ($room->day->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'raw_text' => 'required|string|max:5000',
        ]);

        $rawText  = $data['raw_text'];
        $patients = $room->patients()->get();

        $patientList = $patients->map(fn($p) => "- ID {$p->id} : {$p->name}")->join("\n");

        $prompt = <<<PROMPT
Tu es une assistante médicale pour infirmières. Voici une note vocale dictée dans la chambre {$room->number}.

Patients présents dans cette chambre :
{$patientList}

Note dictée : "{$rawText}"

Pour chaque patient mentionné (ou pour tous si aucun n'est précisé), extrais les informations.
Retourne UNIQUEMENT un JSON valide avec cette structure :
{
  "entries": [
    {
      "patient_id": 12,
      "room_number": "{$room->number}",
      "note_text": "texte de la note pour ce patient",
      "vital": {
        "temperature": null,
        "blood_pressure": null,
        "heart_rate": null,
        "oxygen_saturation": null,
        "respiratory_rate": null,
        "blood_glucose": null,
        "pain_level": null,
        "weight": null
      },
      "checklist_items": [
        {"title": "Emmener chez le médecin", "due_label": "14h30", "priority": "normal"}
      ]
    }
  ],
  "applied_actions": ["Résumé de ce qui a été fait"]
}

Règles :
- Ne traiter que les patients listés ci-dessus (utiliser leur ID exact)
- Si la note ne mentionne aucun patient précis, créer une entrée pour chaque patient
- vital : null si aucune constante mentionnée, sinon l'objet avec les valeurs extraites
- temperature : décimal (ex: 38.5) — mots : "température", "fièvre"
- blood_pressure : chaîne "12/8" ou "120/80" — mots : "tension", "TA"
- heart_rate : entier — mots : "pouls", "FC", "bpm"
- oxygen_saturation : décimal — mots : "saturation", "SpO2", "sat"
- respiratory_rate : entier — mots : "fréquence respiratoire", "FR"
- blood_glucose : décimal — mots : "glycémie", "glucose"
- pain_level : entier 0-10 — mots : "douleur", "EVA"
- weight : décimal — mots : "poids"
- checklist_items : actions planifiées avec heure si présente
- due_label : "14h30", "9h", "16h" ou null
- Ne jamais inventer d'informations
PROMPT;

        $entries       = [];
        $appliedActions = [];

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => config('services.openai.model', 'gpt-4o-mini'),
                    'messages'        => [
                        ['role' => 'system', 'content' => 'Tu es une assistante médicale pour infirmières. Tu extrais des informations structurées depuis des notes vocales. Tu réponds UNIQUEMENT en JSON valide, sans markdown.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens'      => 1200,
                    'temperature'     => 0.1,
                ]);

            $parsed = json_decode($response->json('choices.0.message.content'), true);

            if (!$parsed || !isset($parsed['entries'])) {
                throw new \RuntimeException('Réponse JSON invalide');
            }

            $appliedActions = $parsed['applied_actions'] ?? [];

            foreach ($parsed['entries'] as $entry) {
                $patientId = $entry['patient_id'] ?? null;
                $patient   = $patientId ? $patients->firstWhere('id', $patientId) : null;
                if (!$patient) continue;

                // Save note
                $note = $patient->voiceNotes()->create([
                    'user_id'         => $request->user()->id,
                    'raw_text'        => $rawText,
                    'structured_text' => $entry['note_text'] ?? null,
                ]);

                // Save vitals
                $vital     = null;
                $vitalData = $entry['vital'] ?? null;
                if ($vitalData && is_array($vitalData)) {
                    $hasValue = !empty($vitalData['temperature'])
                        || !empty($vitalData['blood_pressure'])
                        || !empty($vitalData['heart_rate'])
                        || !empty($vitalData['oxygen_saturation'])
                        || !empty($vitalData['respiratory_rate'])
                        || !empty($vitalData['blood_glucose'])
                        || !empty($vitalData['pain_level'])
                        || !empty($vitalData['weight']);

                    if ($hasValue) {
                        $vital = $patient->vitals()->create([
                            'temperature'       => isset($vitalData['temperature'])      ? (float) $vitalData['temperature']      : null,
                            'blood_pressure'    => $vitalData['blood_pressure']          ?? null,
                            'heart_rate'        => isset($vitalData['heart_rate'])       ? (int)   $vitalData['heart_rate']        : null,
                            'oxygen_saturation' => isset($vitalData['oxygen_saturation'])? (float) $vitalData['oxygen_saturation'] : null,
                            'respiratory_rate'  => isset($vitalData['respiratory_rate']) ? (int)   $vitalData['respiratory_rate']  : null,
                            'blood_glucose'     => isset($vitalData['blood_glucose'])    ? (float) $vitalData['blood_glucose']     : null,
                            'pain_level'        => isset($vitalData['pain_level'])       ? (int)   $vitalData['pain_level']        : null,
                            'weight'            => isset($vitalData['weight'])           ? (float) $vitalData['weight']            : null,
                        ]);
                    }
                }

                // Save checklist items
                $checklistItems = [];
                foreach (($entry['checklist_items'] ?? []) as $item) {
                    if (empty($item['title'])) continue;
                    $checklistItems[] = $patient->checklistItems()->create([
                        'title'     => $item['title'],
                        'is_done'   => false,
                        'due_label' => $item['due_label'] ?? null,
                        'priority'  => in_array($item['priority'] ?? '', ['urgent', 'important', 'normal'])
                                       ? $item['priority'] : 'normal',
                    ]);
                }

                $entries[] = [
                    'room_number'     => $room->number,
                    'patient'         => $patient->load('vitals', 'voiceNotes', 'checklistItems'),
                    'vital'           => $vital,
                    'checklist_items' => $checklistItems,
                    'voice_note'      => $note,
                ];
            }

        } catch (\Exception $e) {
            Log::error('RoomVoice AI error', ['error' => $e->getMessage(), 'room' => $room->id]);

            // Fallback: save a raw note for all patients
            foreach ($patients as $patient) {
                $note      = $patient->voiceNotes()->create([
                    'user_id'  => $request->user()->id,
                    'raw_text' => $rawText,
                ]);
                $entries[] = [
                    'room_number'     => $room->number,
                    'patient'         => $patient,
                    'vital'           => null,
                    'checklist_items' => [],
                    'voice_note'      => $note,
                ];
            }
            $appliedActions = ['Note enregistrée (traitement IA indisponible)'];
        }

        return response()->json([
            'entries'         => $entries,
            'applied_actions' => $appliedActions,
        ], 201);
    }
}
