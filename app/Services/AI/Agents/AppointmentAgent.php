<?php

namespace App\Services\AI\Agents;

use App\Models\ChecklistItem;
use App\Models\User;
use App\Services\AI\ExecutionContext;

class AppointmentAgent
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
        return $this->createAppointment($action['data'] ?? [], null);
    }

    public function handleWithContext(array $action, ExecutionContext $context): array
    {
        return $this->createAppointment($action['data'] ?? [], $context);
    }

    // ─────────────────────────────────────────────
    // Appointments are stored as checklist items with a due_label (time)
    // ─────────────────────────────────────────────
    private function createAppointment(array $data, ?ExecutionContext $context): array
    {
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;
        $title       = $data['title']        ?? $data['task'] ?? null;
        $dueLabel    = $data['due_label']    ?? $data['time'] ?? null;

        if (!$title) {
            return ['success' => false, 'error' => 'Titre du rendez-vous manquant'];
        }

        $patient = $context?->getPatient($patientName)
            ?? $this->patientAgent->resolvePatient($patientName, $roomNumber);

        if (!$patient) {
            return ['success' => false, 'error' => "Patient introuvable pour le rendez-vous : {$title}"];
        }

        $item = ChecklistItem::create([
            'patient_id' => $patient->id,
            'title'      => $title,
            'due_label'  => $dueLabel,
            'priority'   => $data['priority'] ?? 'high',
            'is_done'    => false,
        ]);

        $timeInfo = $dueLabel ? " à {$dueLabel}" : '';

        return [
            'success' => true,
            'item'    => $item,
            'patient' => $patient->name,
            'message' => "Rendez-vous « {$title} »{$timeInfo} ajouté pour {$patient->name}",
        ];
    }
}
