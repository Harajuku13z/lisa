<?php

namespace App\Services\AI;

use App\Models\Patient;
use App\Models\Room;
use App\Models\User;

/**
 * Shared state passed between agents during a Lisa orchestrated run.
 *
 * Agents:
 *   - register newly created rooms / patients here so later agents can
 *     resolve them by number / name without hitting the DB,
 *   - read previous agents' results to chain operations correctly.
 */
class ExecutionContext
{
    public User   $user;
    public string $date;

    /** @var array<string, Room>   keyed by normalized room number */
    public array $roomMap = [];

    /** @var array<string, Patient> keyed by lowercase patient name */
    public array $patientMap = [];

    /** Most recently remembered room/patient — used as fallback when an
     *  agent action omits the room_number / patient_name (typical when
     *  the AI just created the entity earlier in the same plan). */
    public ?Room    $lastRoom    = null;
    public ?Patient $lastPatient = null;

    /** @var array<int, array> human-readable per-action results */
    public array $results = [];

    /** @var array<int, string> error messages collected during the run */
    public array $errors = [];

    public function __construct(User $user, string $date)
    {
        $this->user = $user;
        $this->date = $date;
    }

    // ─────────────────────────────────────────────
    public function rememberRoom(Room $room): void
    {
        $this->roomMap[$this->normalizeRoomKey($room->number)] = $room;
        $this->lastRoom = $room;
    }

    public function rememberPatient(Patient $patient): void
    {
        $this->patientMap[$this->normalizePatientKey($patient->name)] = $patient;
        $this->lastPatient = $patient;
    }

    /**
     * Resolve a room from context. When $number is null/empty we return
     * the most recently remembered room (typically the one the previous
     * agent in the same plan just created).
     */
    public function getRoom(?string $number): ?Room
    {
        if ($number === null || $number === '') {
            return $this->lastRoom;
        }
        return $this->roomMap[$this->normalizeRoomKey($number)]
            ?? $this->lastRoom; // fallback when AI used a slightly different number format
    }

    /**
     * Resolve a patient from context. When $name is null/empty we return
     * the most recently remembered patient (so chained actions like
     * "create patient + add vital" work even if the AI omitted the name
     * on the second action).
     */
    public function getPatient(?string $name): ?Patient
    {
        if ($name === null || $name === '') {
            return $this->lastPatient;
        }
        $key = $this->normalizePatientKey($name);
        if (isset($this->patientMap[$key])) {
            return $this->patientMap[$key];
        }
        // Fallback: substring match against any registered patient
        foreach ($this->patientMap as $k => $patient) {
            if (str_contains($k, $key) || str_contains($key, $k)) {
                return $patient;
            }
        }
        return $this->lastPatient;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addResult(array $result): void
    {
        $this->results[] = $result;
    }

    // ─────────────────────────────────────────────
    private function normalizeRoomKey(?string $number): string
    {
        return mb_strtolower(trim((string) $number));
    }

    private function normalizePatientKey(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
}
