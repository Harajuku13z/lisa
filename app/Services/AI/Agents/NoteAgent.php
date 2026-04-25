<?php

namespace App\Services\AI\Agents;

use App\Models\User;
use App\Models\VoiceNote;

class NoteAgent
{
    private PatientAgent $patientAgent;

    public function __construct(
        private User   $user,
        private string $date
    ) {
        $this->patientAgent = new PatientAgent($user, $date);
    }

    public function handle(array $action): array
    {
        return match ($action['intent'] ?? '') {
            'create_note' => $this->createNote($action['data'] ?? []),
            default       => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    private function createNote(array $data): array
    {
        $content     = $data['content'] ?? $data['note'] ?? $data['text'] ?? null;
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;

        if (!$content) {
            return ['success' => false, 'error' => 'Contenu de la note manquant'];
        }

        $patientId = null;

        if ($patientName) {
            $patient   = $this->patientAgent->resolvePatient($patientName, $roomNumber);
            $patientId = $patient?->id;
        }

        $note = VoiceNote::create([
            'user_id'         => $this->user->id,
            'patient_id'      => $patientId,
            'raw_text'        => $content,
            'structured_text' => null,
        ]);

        $target = $patientName ? " pour {$patientName}" : '';
        return [
            'success' => true,
            'note'    => $note,
            'message' => "Note enregistrée{$target}",
        ];
    }
}
