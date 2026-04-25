<?php

namespace App\Services\AI\Agents;

use App\Models\ChecklistItem;
use App\Models\User;

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
        return match ($action['intent'] ?? '') {
            'create_appointment' => $this->createAppointment($action['data'] ?? []),
            default              => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    // Appointments are stored as checklist items with a due_label (time)
    // ─────────────────────────────────────────────
    private function createAppointment(array $data): array
    {
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;
        $title       = $data['title']        ?? $data['task'] ?? null;
        $dueLabel    = $data['due_label']    ?? $data['time'] ?? null;

        if (!$title) {
            return ['success' => false, 'error' => 'Titre du rendez-vous manquant'];
        }

        $patient = $this->patientAgent->resolvePatient($patientName, $roomNumber);

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
