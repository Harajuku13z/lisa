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
        $data = $request->validate(['text' => 'required|string|max:5000']);

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
          "actions": ["pansement fait", "constantes relevées"]
        }
      ]
    }
  ],
  "raw_text": "texte original"
}

Règles :
- Ne jamais inventer d'informations non présentes dans le texte
- Ne jamais faire de diagnostic médical
- Si le nom du patient est absent, utiliser "Patient inconnu"
- Si le numéro de chambre est absent, utiliser "0"
- Conserver les actions exactement comme dictées
- raw_text doit contenir le texte original

Texte à analyser : {$data['text']}
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

            $parsed['raw_text'] = $data['text'];
            return response()->json($parsed);
        } catch (\Exception $e) {
            Log::error('OpenAI error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Service IA temporairement indisponible'], 503);
        }
    }
}
