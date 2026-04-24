<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChecklistItem;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChecklistController extends Controller {
    public function index(Request $request, Patient $patient) {
        $this->authorize($request, $patient);
        return response()->json($patient->checklistItems);
    }

    public function generate(Request $request, Patient $patient) {
        $this->authorize($request, $patient);

        $context = implode("\n", [
            "Patient : {$patient->name}",
            $patient->age ? "Âge : {$patient->age} ans" : '',
            $patient->diagnosis ? "Diagnostic : {$patient->diagnosis}" : '',
        ]);

        $prompt = <<<PROMPT
Tu es une assistante pour infirmières. Génère une checklist de soins simple et pratique pour ce patient.
Retourne UNIQUEMENT un JSON : {"items": ["Soin 1", "Soin 2", ...]}
Maximum 8 éléments. Sois concis. Ne fais pas de diagnostic médical.
Informations patient :
{$context}
PROMPT;

        try {
            $response = Http::withToken(config('services.openai.key'))
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => config('services.openai.model', 'gpt-4o-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => 'Tu génères des checklists de soins infirmiers. JSON uniquement.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'max_tokens' => 400,
                    'temperature' => 0.2,
                ]);

            $content = $response->json('choices.0.message.content');
            $parsed = json_decode($content, true);
            $items = $parsed['items'] ?? [];

            $patient->checklistItems()->delete();
            $created = [];
            foreach ($items as $item) {
                $created[] = $patient->checklistItems()->create(['title' => $item, 'is_done' => false]);
            }

            return response()->json($created, 201);
        } catch (\Exception $e) {
            Log::error('Checklist generation error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Service IA indisponible'], 503);
        }
    }

    public function update(Request $request, ChecklistItem $checklistItem) {
        if ($checklistItem->patient->room->day->user_id !== $request->user()->id) abort(403);
        $data = $request->validate(['is_done' => 'required|boolean']);
        $checklistItem->update($data);
        return response()->json($checklistItem);
    }

    private function authorize(Request $request, Patient $patient): void {
        if ($patient->room->day->user_id !== $request->user()->id) abort(403);
    }
}
