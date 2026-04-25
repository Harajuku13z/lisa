<?php

namespace App\Services\AI\Agents;

use App\Models\ChecklistItem;
use App\Models\Day;
use App\Models\Room;
use App\Models\User;

class ReminderAgent
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
            'create_reminder' => $this->createReminder($action['data'] ?? []),
            default           => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    // Reminders without a patient are stored on a global day-level "reminder room"
    // Reminders with a patient are stored as checklist items on that patient
    // ─────────────────────────────────────────────
    private function createReminder(array $data): array
    {
        $title       = $data['title']        ?? null;
        $remindAt    = $data['remind_at']    ?? $data['time'] ?? null;
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;

        if (!$title) {
            return ['success' => false, 'error' => 'Titre du rappel manquant'];
        }

        // If a patient is mentioned, attach reminder to their checklist
        if ($patientName) {
            $patient = $this->patientAgent->resolvePatient($patientName, $roomNumber);

            if ($patient) {
                $item = ChecklistItem::create([
                    'patient_id' => $patient->id,
                    'title'      => $title,
                    'due_label'  => $remindAt,
                    'priority'   => $data['priority'] ?? 'normal',
                    'is_done'    => false,
                ]);

                $timeInfo = $remindAt ? " à {$remindAt}" : '';
                return [
                    'success' => true,
                    'item'    => $item,
                    'patient' => $patient->name,
                    'message' => "Rappel « {$title} »{$timeInfo} ajouté pour {$patient->name}",
                ];
            }
        }

        // No patient → attach to the first available room of the day (or create a generic one)
        $day = Day::firstOrCreate(
            ['user_id' => $this->user->id, 'date' => $this->date],
            ['note' => null]
        );

        // Find or create a catch-all room for day-level reminders
        $room = Room::firstOrCreate(
            ['day_id' => $day->id, 'number' => 'Rappels'],
            []
        );

        // Create a generic patient "Rappels" if needed
        $patient = \App\Models\Patient::firstOrCreate(
            ['room_id' => $room->id, 'name' => 'Rappels infirmiers'],
            ['full_name' => 'Rappels infirmiers', 'initials' => 'RI']
        );

        $item = ChecklistItem::create([
            'patient_id' => $patient->id,
            'title'      => $title,
            'due_label'  => $remindAt,
            'priority'   => $data['priority'] ?? 'normal',
            'is_done'    => false,
        ]);

        $timeInfo = $remindAt ? " à {$remindAt}" : '';
        return [
            'success' => true,
            'item'    => $item,
            'message' => "Rappel général « {$title} »{$timeInfo} créé",
        ];
    }
}
