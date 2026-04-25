<?php

namespace App\Services\AI\Agents;

use App\Models\Day;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class RoomAgent
{
    public function __construct(
        private User   $user,
        private string $date
    ) {}

    public function handle(array $action): array
    {
        return match ($action['intent'] ?? '') {
            'create_room' => $this->createRoom($action['data'] ?? []),
            'find_room'   => $this->findRoom($action['data'] ?? []),
            default       => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    private function createRoom(array $data): array
    {
        $roomNumber = $data['room_number'] ?? null;

        if (!$roomNumber) {
            return ['success' => false, 'error' => 'Numéro de chambre manquant'];
        }

        // Get or create the Day for the given date
        $day = Day::firstOrCreate(
            ['user_id' => $this->user->id, 'date' => $this->date],
            ['note' => null]
        );

        // Avoid creating a duplicate room
        $existing = Room::where('day_id', $day->id)
            ->where('number', (string) $roomNumber)
            ->first();

        if ($existing) {
            return [
                'success' => true,
                'room'    => $existing,
                'message' => "Chambre {$roomNumber} existe déjà",
            ];
        }

        $room = Room::create([
            'day_id' => $day->id,
            'number' => (string) $roomNumber,
        ]);

        return [
            'success' => true,
            'room'    => $room,
            'message' => "Chambre {$roomNumber} créée",
        ];
    }

    // ─────────────────────────────────────────────
    private function findRoom(array $data): array
    {
        $roomNumber = $data['room_number'] ?? null;

        if (!$roomNumber) {
            return ['success' => false, 'error' => 'Numéro de chambre manquant'];
        }

        $day = Day::where('user_id', $this->user->id)
            ->where('date', $this->date)
            ->first();

        if (!$day) {
            return ['success' => false, 'error' => 'Aucune journée trouvée pour cette date'];
        }

        $room = Room::where('day_id', $day->id)
            ->where('number', (string) $roomNumber)
            ->first();

        if (!$room) {
            return ['success' => false, 'error' => "Chambre {$roomNumber} introuvable"];
        }

        return ['success' => true, 'room' => $room];
    }

    // ─────────────────────────────────────────────
    // Public helper used by other agents
    // ─────────────────────────────────────────────
    public function resolveRoom(?string $roomNumber): ?Room
    {
        if (!$roomNumber) return null;

        $day = Day::where('user_id', $this->user->id)
            ->where('date', $this->date)
            ->first();

        if (!$day) return null;

        return Room::where('day_id', $day->id)
            ->where('number', (string) $roomNumber)
            ->first();
    }
}
