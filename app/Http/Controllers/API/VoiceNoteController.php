<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\VoiceNote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceNoteController extends Controller {
    public function store(Request $request) {
        $data = $request->validate([
            'patient_id' => 'nullable|integer|exists:patients,id',
            'raw_text'   => 'required|string',
        ]);

        if (isset($data['patient_id'])) {
            $patient = Patient::findOrFail($data['patient_id']);
            if ($patient->room->day->user_id !== $request->user()->id) abort(403);
        }

        $note = VoiceNote::create([
            'user_id'    => $request->user()->id,
            'patient_id' => $data['patient_id'] ?? null,
            'raw_text'   => $data['raw_text'],
        ]);

        return response()->json($note, 201);
    }

    public function structure(Request $request) {
        $data = $request->validate([
            'raw_text' => 'required_without:text|string|max:5000',
            'text'     => 'required_without:raw_text|string|max:5000',
        ]);
        $inputText = $data['raw_text'] ?? $data['text'];

        $prompt = <<<PROMPT
Tu es une assistante pour infirmières. Analyse ce texte dicté et extrais les informations suivantes.
Retourne UNIQUEMENT un JSON valide avec cette structure :
{
  "rooms": [
    {
      "number": "312",
      "patients": [
        {
          "name": "Mme Dupont",
          "actions": ["température 38.5", "pansement fait", "rendez-vous médecin à 14h"]
        }
      ]
    }
  ],
  "raw_text": "texte original"
}

Règles :
- Ne jamais inventer d'informations non présentes dans le texte
- Ne jamais faire de diagnostic médical
- Si le nom du patient n'est PAS mentionné dans le texte, mettre "name": null (pas une chaîne vide, la valeur null)
- Si le numéro de chambre n'est PAS mentionné, utiliser "0"
- Mettre dans "actions" TOUT ce qui est dit sur le patient : constantes (température, tension, SpO2, pouls), observations cliniques, soins effectués, rendez-vous, médicaments, instructions
- Conserver les actions exactement comme dictées, avec les valeurs chiffrées (ex: "température 38.5", "saturation 96", "tension 12/8")
- raw_text doit contenir le texte original
- Ne jamais utiliser "Patient inconnu" — préférer null si le nom est absent

Texte à analyser : {$inputText}
PROMPT;

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Tu es une assistante médicale pour infirmières. Tu analyses des notes vocales et extrais des informations structurées. Tu réponds UNIQUEMENT en JSON valide.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 1500,
                    'temperature' => 0.1,
                ]);

            $content = $response->json('choices.0.message.content');
            $parsed = json_decode($content, true);

            if (!$parsed || !isset($parsed['rooms'])) {
                return response()->json(['error' => 'Impossible de structurer le texte'], 422);
            }

            $parsed['raw_text'] = $inputText;
            return response()->json($parsed);
        } catch (\Exception $e) {
            Log::error('OpenAI error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Service IA temporairement indisponible'], 503);
        }
    }
}
