<?php

namespace App\Services\AI\Agents;

use App\Models\Day;
use App\Models\Room;
use App\Models\User;
use App\Services\AI\ExecutionContext;
use Illuminate\Support\Facades\Log;

class RoomAgent
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
            'create_room' => $this->createRoom($action['data'] ?? [], $context),
            'find_room'   => $this->findRoom($action['data'] ?? [], $context),
            default       => ['success' => false, 'error' => 'Intent inconnue : ' . ($action['intent'] ?? 'null')],
        };
    }

    // ─────────────────────────────────────────────
    private function createRoom(array $data, ?ExecutionContext $context = null): array
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
            $context?->rememberRoom($existing);
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

        $context?->rememberRoom($room);

        return [
            'success' => true,
            'room'    => $room,
            'message' => "Chambre {$roomNumber} créée",
        ];
    }

    // ─────────────────────────────────────────────
    private function findRoom(array $data, ?ExecutionContext $context = null): array
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

        $context?->rememberRoom($room);
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
