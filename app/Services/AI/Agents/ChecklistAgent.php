<?php

namespace App\Services\AI\Agents;

use App\Models\ChecklistItem;
use App\Models\User;

class ChecklistAgent
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
            'create_checklist_item' => $this->createItem($action['data'] ?? []),
            'toggle_checklist_item' => $this->toggleItem($action['data'] ?? []),
            default                 => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    private function createItem(array $data): array
    {
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;
        $task        = $data['task']         ?? null;

        if (!$task) {
            return ['success' => false, 'error' => 'Tâche manquante'];
        }

        $patient = $this->patientAgent->resolvePatient($patientName, $roomNumber);

        if (!$patient) {
            return ['success' => false, 'error' => "Patient introuvable pour la tâche : {$task}"];
        }

        $status = $data['status'] ?? 'pending';
        $isDone = in_array($status, ['done', 'completed', 'fait', 'terminé']);

        $item = ChecklistItem::create([
            'patient_id' => $patient->id,
            'title'      => $task,
            'due_label'  => $data['due_label'] ?? null,
            'priority'   => $data['priority']  ?? 'normal',
            'is_done'    => $isDone,
        ]);

        return [
            'success' => true,
            'item'    => $item,
            'patient' => $patient->name,
            'message' => "Tâche « {$task} » ajoutée pour {$patient->name}",
        ];
    }

    // ─────────────────────────────────────────────
    private function toggleItem(array $data): array
    {
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;
        $task        = $data['task']         ?? null;

        if (!$task) {
            return ['success' => false, 'error' => 'Tâche à cocher manquante'];
        }

        $patient = $this->patientAgent->resolvePatient($patientName, $roomNumber);
        if (!$patient) {
            return ['success' => false, 'error' => 'Patient introuvable'];
        }

        $item = ChecklistItem::where('patient_id', $patient->id)
            ->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower($task) . '%'])
            ->first();

        if (!$item) {
            return ['success' => false, 'error' => "Tâche « {$task} » introuvable"];
        }

        $item->update(['is_done' => !$item->is_done]);

        $state = $item->is_done ? 'cochée' : 'décochée';
        return [
            'success' => true,
            'item'    => $item,
            'message' => "Tâche « {$task} » {$state}",
        ];
    }
}
