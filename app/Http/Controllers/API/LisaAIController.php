<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\AI\LisaOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LisaAIController extends Controller
{
    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'    => 'required|string|max:5000',
            'source'     => 'nullable|string|in:text,voice',
            'patient_id' => 'nullable|integer|exists:patients,id',
            'room_id'    => 'nullable|integer|exists:rooms,id',
        ]);

        $user = $request->user();
        $date = now()->toDateString();

        $orchestrator = new LisaOrchestrator($user, $date);
        $orchestrator->preloadPatient($data['patient_id'] ?? null);
        $orchestrator->preloadRoom($data['room_id'] ?? null);

        $result = $orchestrator->handle($data['message'], $data['source'] ?? 'text');

        // Toujours répondre en 200 — le client lit `success` dans le JSON.
        // Cela évite que Swift transforme la réponse en APIError.serverError(422)
        // et perde le message structuré de Lisa.
        return response()->json($result, 200);
    }
}
