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
            'message' => 'required|string|max:5000',
            'source'  => 'nullable|string|in:text,voice',
        ]);

        $user = $request->user();
        $date = now()->toDateString();

        $orchestrator = new LisaOrchestrator($user, $date);
        $result       = $orchestrator->handle($data['message'], $data['source'] ?? 'text');

        $status = ($result['success'] ?? false) ? 200 : 422;

        // Confirmation requests should return 200 so the iOS client shows the question
        if (!empty($result['needs_confirmation'])) {
            $status = 200;
        }

        return response()->json($result, $status);
    }
}
