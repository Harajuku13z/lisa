<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PatientNoteController extends Controller {

    public function process(Request $request, Patient $patient) {
        // Authorization
        if (!$patient->room || !$patient->room->day) abort(404);
        if ($patient->room->day->user_id !== $request->user()->id) abort(403);

        $data = $request->validate([
            'raw_text' => 'required|string|max:5000',
        ]);

        $rawText = $data['raw_text'];

        // 1. Save the raw voice note
        $note = $patient->voiceNotes()->create([
            'user_id'  => $request->user()->id,
            'raw_text' => $rawText,
        ]);

        // 2. Call OpenAI to extract structured information
        $patientContext = implode("\n", array_filter([
            "Patient : {$patient->name}",
            $patient->age    ? "Âge : {$patient->age} ans"         : null,
            $patient->diagnosis ? "Diagnostic : {$patient->diagnosis}" : null,
        ]));

        $prompt = <<<PROMPT
Tu es une assistante médicale pour infirmières. Analyse cette note et extrais les informations suivantes.

Contexte patient :
{$patientContext}

Note dictée : "{$rawText}"

Retourne UNIQUEMENT un JSON valide avec cette structure exacte :
{
  "summary": "Résumé concis en 1 phrase",
  "observations": "Observations cliniques si présentes, sinon null",
  "vital": {
    "temperature": null,
    "blood_pressure": null,
    "heart_rate": null,
    "oxygen_saturation": null,
    "respiratory_rate": null,
    "blood_glucose": null,
    "pain_level": null,
    "weight": null,
    "notes": null
  },
  "checklist_items": []
}

Règles pour "vital" :
- Si aucune constante n'est mentionnée dans le texte, mettre vital à null (pas l'objet, la valeur null)
- temperature : nombre décimal (ex: 38.5) — mots-clés : "température", "temp", "fièvre" suivi d'un chiffre
- blood_pressure : chaîne "120/80" — mots-clés : "tension", "TA", "pression artérielle"
- heart_rate : entier (ex: 72) — mots-clés : "pouls", "fréquence cardiaque", "FC", "bpm"
- oxygen_saturation : nombre décimal (ex: 96.0) — mots-clés : "saturation", "SpO2", "sat"
- respiratory_rate : entier (ex: 18) — mots-clés : "fréquence respiratoire", "FR"
- blood_glucose : nombre décimal (ex: 1.2) — mots-clés : "glycémie", "glucose"
- pain_level : entier 0-10 — mots-clés : "douleur", "EVA", "score douleur"
- weight : nombre décimal (ex: 72.5) — mots-clés : "poids", "kg"
- Si une seule constante est mentionnée, les autres restent null

Règles pour "checklist_items" :
- Créer un élément pour CHAQUE action programmée ou rendez-vous avec une heure
- title : description courte de l'action (ex: "Emmener chez le médecin")
- due_label : heure au format "Xh" ou "XhYY" (ex: "14h35", "9h", "16h30") ou null si pas d'heure
- priority : "urgent", "important", ou "normal"
- Exemples de phrases créant un checklist_item : "rendez-vous à 14h35", "prise de sang à 10h", "médecin à 16h", "rappeler à 9h30"

Règles générales :
- Ne jamais inventer d'informations absentes du texte
- Ne jamais faire de diagnostic
PROMPT;

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
                    'max_tokens'      => 800,
                    'temperature'     => 0.1,
                ]);

            $content = $response->json('choices.0.message.content');
            $parsed  = json_decode($content, true);

            if (!$parsed) {
                throw new \RuntimeException('Réponse JSON invalide de l\'IA');
            }

            // 3. Update note with structured text
            $note->update(['structured_text' => $parsed['summary'] ?? null]);

            // 4. Create vital sign if any value is present
            $vital = null;
            $vitalData = $parsed['vital'] ?? null;
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
                        'temperature'        => isset($vitalData['temperature'])        ? (float) $vitalData['temperature']        : null,
                        'blood_pressure'     => $vitalData['blood_pressure']            ?? null,
                        'heart_rate'         => isset($vitalData['heart_rate'])         ? (int)   $vitalData['heart_rate']          : null,
                        'oxygen_saturation'  => isset($vitalData['oxygen_saturation'])  ? (float) $vitalData['oxygen_saturation']   : null,
                        'respiratory_rate'   => isset($vitalData['respiratory_rate'])   ? (int)   $vitalData['respiratory_rate']    : null,
                        'blood_glucose'      => isset($vitalData['blood_glucose'])      ? (float) $vitalData['blood_glucose']       : null,
                        'pain_level'         => isset($vitalData['pain_level'])         ? (int)   $vitalData['pain_level']          : null,
                        'weight'             => isset($vitalData['weight'])             ? (float) $vitalData['weight']              : null,
                        'notes'              => $vitalData['notes']                     ?? null,
                    ]);
                }
            }

            // 5. Create checklist items
            $checklistItems = [];
            foreach (($parsed['checklist_items'] ?? []) as $item) {
                if (empty($item['title'])) continue;
                $checklistItems[] = $patient->checklistItems()->create([
                    'title'     => $item['title'],
                    'is_done'   => false,
                    'due_label' => $item['due_label'] ?? null,
                    'priority'  => in_array($item['priority'] ?? '', ['urgent', 'important', 'normal'])
                                   ? $item['priority']
                                   : 'normal',
                ]);
            }

            return response()->json([
                'note'            => $note->fresh(),
                'vital'           => $vital,
                'checklist_items' => $checklistItems,
                'observations'    => $parsed['observations'] ?? null,
                'summary'         => $parsed['summary'] ?? 'Note enregistrée',
            ], 201);

        } catch (\Exception $e) {
            Log::error('PatientNote AI error', ['error' => $e->getMessage(), 'patient' => $patient->id]);

            // Even on AI failure, the note was saved — return it
            return response()->json([
                'note'            => $note->fresh(),
                'vital'           => null,
                'checklist_items' => [],
                'observations'    => null,
                'summary'         => 'Note enregistrée (traitement IA indisponible)',
            ], 201);
        }
    }
}
