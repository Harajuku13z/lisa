<?php

namespace App\Services\AI\Agents;

use App\Models\Day;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;

class PatientAgent
{
    public function __construct(
        private User   $user,
        private string $date
    ) {}

    public function handle(array $action): array
    {
        return match ($action['intent'] ?? '') {
            'create_patient' => $this->createPatient($action['data'] ?? []),
            'find_patient'   => $this->findPatient($action['data'] ?? []),
            default          => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    private function createPatient(array $data): array
    {
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;

        if (!$patientName) {
            return ['success' => false, 'error' => 'Nom du patient manquant'];
        }

        $room = $this->resolveRoom($roomNumber);

        if (!$room) {
            // Auto-create room if number was given
            if ($roomNumber) {
                $roomAgent = new RoomAgent($this->user, $this->date);
                $result    = $roomAgent->handle(['intent' => 'create_room', 'data' => ['room_number' => $roomNumber]]);
                $room      = $result['room'] ?? null;
            }

            if (!$room) {
                return ['success' => false, 'error' => 'Chambre introuvable et aucun numéro fourni'];
            }
        }

        // Avoid duplicate patient in same room
        $existing = Patient::where('room_id', $room->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($patientName)])
            ->first();

        if ($existing) {
            return [
                'success' => true,
                'patient' => $existing,
                'message' => "{$patientName} existe déjà dans la chambre {$room->number}",
            ];
        }

        $patient = Patient::create([
            'room_id'   => $room->id,
            'name'      => $patientName,
            'full_name' => $patientName,
            'initials'  => $this->makeInitials($patientName),
            'age'       => $data['age']    ?? null,
            'gender'    => $data['gender'] ?? null,
            'diagnosis' => $data['diagnosis'] ?? null,
        ]);

        return [
            'success' => true,
            'patient' => $patient,
            'room'    => $room,
            'message' => "Patient {$patientName} créé dans la chambre {$room->number}",
        ];
    }

    // ─────────────────────────────────────────────
    private function findPatient(array $data): array
    {
        $patient = $this->resolvePatient($data['patient_name'] ?? null, $data['room_number'] ?? null);

        if (!$patient) {
            return ['success' => false, 'error' => 'Patient introuvable'];
        }

        return ['success' => true, 'patient' => $patient];
    }

    // ─────────────────────────────────────────────
    // Public helpers used by other agents
    // ─────────────────────────────────────────────
    public function resolvePatient(?string $name, ?string $roomNumber): ?Patient
    {
        if (!$name) return null;

        $query = Patient::query()
            ->whereHas('room.day', fn ($q) => $q->where('user_id', $this->user->id)->where('date', $this->date))
            ->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($name) . '%']);

        if ($roomNumber) {
            $query->whereHas('room', fn ($q) => $q->where('number', (string) $roomNumber));
        }

        return $query->first();
    }

    public function resolveRoom(?string $roomNumber): ?Room
    {
        if (!$roomNumber) return null;

        $day = Day::where('user_id', $this->user->id)
            ->where('date', $this->date)
            ->first();

        if (!$day) return null;

        return Room::where('day_id', $day->id)->where('number', (string) $roomNumber)->first();
    }

    private function makeInitials(string $name): string
    {
        $words = array_filter(explode(' ', mb_strtoupper(trim($name))));
        if (count($words) >= 2) {
            return mb_substr(array_shift($words), 0, 1) . mb_substr(array_shift($words), 0, 1);
        }
        return mb_substr($name, 0, 2, 'UTF-8');
    }
}
