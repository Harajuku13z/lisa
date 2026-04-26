<?php
namespace App\Services;

use App\Models\Day;
use App\Models\Room;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds the immutable JSON snapshot of a nurse's day for a transmission,
 * and applies a snapshot back into another day (recipient or self/next-day).
 *
 * Personal voice notes are NEVER included — only structured clinical data:
 *   - rooms.number
 *   - patients.{name, age, gender, diagnosis}
 *   - vitals.{temperature, blood_pressure, heart_rate, oxygen_saturation,
 *             respiratory_rate, blood_glucose, pain_level, weight, notes_clinical}
 *   - checklist_items.{title, due_label, priority, is_done}
 */
class DaySnapshotService
{
    /**
     * Build the JSON snapshot for a given user + date.
     * Returns ['source_date' => 'YYYY-MM-DD', 'rooms' => [...]].
     */
    public function build(User $user, string $date): array
    {
        $day = Day::where('user_id', $user->id)->where('date', $date)->first();
        if (!$day) {
            return [
                'source_date' => $date,
                'rooms'       => [],
            ];
        }

        $rooms = $day->rooms()
            ->with([
                'patients.vitals',
                'patients.checklistItems',
            ])
            ->get();

        $payloadRooms = $rooms->map(function (Room $room) {
            return [
                'number'   => (string) $room->number,
                'patients' => $room->patients->map(function (Patient $p) {
                    return [
                        'name'      => $p->name,
                        'age'       => $p->age,
                        'gender'    => $p->gender,
                        'diagnosis' => $p->diagnosis,
                        'vitals'    => $p->vitals->map(fn($v) => [
                            'temperature'       => $v->temperature,
                            'blood_pressure'    => $v->blood_pressure,
                            'heart_rate'        => $v->heart_rate,
                            'oxygen_saturation' => $v->oxygen_saturation,
                            'respiratory_rate'  => $v->respiratory_rate,
                            'blood_glucose'     => $v->blood_glucose,
                            'pain_level'        => $v->pain_level,
                            'weight'            => $v->weight,
                            // Clinical observation note attached to the vital reading is OK to share —
                            // it documents the measurement context, not personal notes from the nurse.
                            'notes'             => $v->notes,
                            'recorded_at'       => optional($v->created_at)->toIso8601String(),
                        ])->values()->all(),
                        'checklist' => $p->checklistItems->map(fn($c) => [
                            'title'     => $c->title,
                            'due_label' => $c->due_label,
                            'priority'  => $c->priority,
                            'is_done'   => (bool) $c->is_done,
                        ])->values()->all(),
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return [
            'source_date' => $date,
            'rooms'       => $payloadRooms,
        ];
    }

    /**
     * Apply a snapshot into target user + target date.
     *
     * Strategy: NEVER overwrite. We MERGE — if a room with the same number
     * already exists for that target date, we merge patients into it. Existing
     * vitals & checklist are preserved; the snapshot's items are appended.
     *
     * Returns counts of what was added.
     */
    public function apply(User $targetUser, string $targetDate, array $payload): array
    {
        $stats = [
            'rooms'    => 0,
            'patients' => 0,
            'vitals'   => 0,
            'checklist'=> 0,
        ];

        return DB::transaction(function () use ($targetUser, $targetDate, $payload, &$stats) {
            $day = Day::firstOrCreate(
                ['user_id' => $targetUser->id, 'date' => $targetDate]
            );

            foreach (($payload['rooms'] ?? []) as $roomData) {
                $roomNumber = (string) ($roomData['number'] ?? '');
                if ($roomNumber === '') continue;

                $room = $day->rooms()->where('number', $roomNumber)->first();
                if (!$room) {
                    $room = $day->rooms()->create(['number' => $roomNumber]);
                    $stats['rooms']++;
                }

                foreach (($roomData['patients'] ?? []) as $patientData) {
                    $name = trim((string) ($patientData['name'] ?? ''));
                    if ($name === '') continue;

                    // Match by case-insensitive name within the room — avoids dupes when
                    // the same patient is transmitted across shifts.
                    $patient = $room->patients()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                        ->first();

                    if (!$patient) {
                        $patient = $room->patients()->create([
                            'name'      => $name,
                            'age'       => $patientData['age'] ?? null,
                            'gender'    => $patientData['gender'] ?? null,
                            'diagnosis' => $patientData['diagnosis'] ?? null,
                        ]);
                        $stats['patients']++;
                    }

                    foreach (($patientData['vitals'] ?? []) as $v) {
                        $patient->vitals()->create([
                            'temperature'       => $v['temperature']       ?? null,
                            'blood_pressure'    => $v['blood_pressure']    ?? null,
                            'heart_rate'        => $v['heart_rate']        ?? null,
                            'oxygen_saturation' => $v['oxygen_saturation'] ?? null,
                            'respiratory_rate'  => $v['respiratory_rate']  ?? null,
                            'blood_glucose'     => $v['blood_glucose']     ?? null,
                            'pain_level'        => $v['pain_level']        ?? null,
                            'weight'            => $v['weight']            ?? null,
                            'notes'             => $v['notes']             ?? null,
                        ]);
                        $stats['vitals']++;
                    }

                    foreach (($patientData['checklist'] ?? []) as $c) {
                        if (empty($c['title'])) continue;
                        $patient->checklistItems()->create([
                            'title'     => $c['title'],
                            'is_done'   => (bool) ($c['is_done'] ?? false),
                            'due_label' => $c['due_label'] ?? null,
                            'priority'  => in_array(($c['priority'] ?? ''), ['urgent', 'important', 'normal'])
                                ? $c['priority'] : 'normal',
                        ]);
                        $stats['checklist']++;
                    }
                }
            }

            return $stats;
        });
    }
}
