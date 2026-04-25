<?php

namespace App\Services\AI\Agents;

use App\Models\Day;
use App\Models\Patient;
use App\Models\Room;
use App\Models\User;
use App\Services\AI\ExecutionContext;

class PatientAgent
{
    public function __construct(
        private User   $user,
        private string $date
    ) {}

    public function handle(array $action): array
    {
        return $this->dispatch($action, null);
    }

    public function handleWithContext(array $action, ExecutionContext $context): array
    {
        return $this->dispatch($action, $context);
    }

    private function dispatch(array $action, ?ExecutionContext $context): array
    {
        return match ($action['intent'] ?? '') {
            'create_patient' => $this->createPatient($action['data'] ?? [], $context),
            'find_patient'   => $this->findPatient($action['data'] ?? [], $context),
            default          => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    private function createPatient(array $data, ?ExecutionContext $context): array
    {
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;

        if (!$patientName) {
            return ['success' => false, 'error' => 'Nom du patient manquant'];
        }

        // 1. Try to find the room — first in context, then DB, then auto-create
        $room = $context?->getRoom($roomNumber) ?? $this->resolveRoom($roomNumber);

        if (!$room && $roomNumber) {
            $roomAgent = new RoomAgent($this->user, $this->date);
            $result    = method_exists($roomAgent, 'handleWithContext') && $context
                ? $roomAgent->handleWithContext(['intent' => 'create_room', 'data' => ['room_number' => $roomNumber]], $context)
                : $roomAgent->handle(['intent' => 'create_room', 'data' => ['room_number' => $roomNumber]]);
            $room = $result['room'] ?? null;
        }

        if (!$room) {
            return ['success' => false, 'error' => 'Chambre introuvable et aucun numéro fourni'];
        }

        // 2. Avoid duplicate patient in same room
        $existing = Patient::where('room_id', $room->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($patientName)])
            ->first();

        if ($existing) {
            $context?->rememberPatient($existing);
            $context?->rememberRoom($room);
            return [
                'success' => true,
                'patient' => $existing,
                'room'    => $room,
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

        $context?->rememberPatient($patient);
        $context?->rememberRoom($room);

        return [
            'success' => true,
            'patient' => $patient,
            'room'    => $room,
            'message' => "Patient {$patientName} créé dans la chambre {$room->number}",
        ];
    }

    // ─────────────────────────────────────────────
    private function findPatient(array $data, ?ExecutionContext $context): array
    {
        $patient = $context?->getPatient($data['patient_name'] ?? null)
            ?? $this->resolvePatient($data['patient_name'] ?? null, $data['room_number'] ?? null);

        if (!$patient) {
            return ['success' => false, 'error' => 'Patient introuvable'];
        }

        $context?->rememberPatient($patient);

        return ['success' => true, 'patient' => $patient];
    }

    // ─────────────────────────────────────────────
    // Public helpers used by other agents
    // ─────────────────────────────────────────────
    public function resolvePatient(?string $name, ?string $roomNumber): ?Patient
    {
        if (!$name) return null;

        // NOTE: relation `room` was renamed to `assignedRoom` because the
        // legacy `room` column on the patients table shadowed it.
        $query = Patient::query()
            ->whereHas('assignedRoom.day', fn ($q) => $q
                ->where('user_id', $this->user->id)
                ->where('date', $this->date))
            ->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($name) . '%']);

        if ($roomNumber) {
            $query->whereHas('assignedRoom', fn ($q) => $q->where('number', (string) $roomNumber));
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
