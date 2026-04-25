<?php

namespace App\Services\AI\Agents;

use App\Models\User;
use App\Models\VoiceNote;
use App\Services\AI\ExecutionContext;

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
        return $this->createNote($action['data'] ?? [], null);
    }

    public function handleWithContext(array $action, ExecutionContext $context): array
    {
        return $this->createNote($action['data'] ?? [], $context);
    }

    // ─────────────────────────────────────────────
    private function createNote(array $data, ?ExecutionContext $context): array
    {
        $content     = $data['content'] ?? $data['note'] ?? $data['text'] ?? null;
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;

        if (!$content) {
            return ['success' => false, 'error' => 'Contenu de la note manquant'];
        }

        $patientId = null;

        if ($patientName) {
            $patient   = $context?->getPatient($patientName)
                ?? $this->patientAgent->resolvePatient($patientName, $roomNumber);
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
