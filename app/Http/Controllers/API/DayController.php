<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Day;
use Illuminate\Http\Request;

class DayController extends Controller {
    public function index(Request $request) {
        $days = Day::where('user_id', $request->user()->id)
            ->withCount(['rooms', 'rooms as patient_count' => fn($q) => $q->join('patients', 'patients.room_id', '=', 'rooms.id')])
            ->orderByDesc('date')
            ->get()
            ->map(fn($d) => [
                'date'          => $d->date,
                'room_count'    => $d->rooms_count,
                'patient_count' => $d->patient_count,
            ]);
        return response()->json($days);
    }

    public function show(Request $request, string $date) {
        $day = Day::firstOrCreate(
            ['user_id' => $request->user()->id, 'date' => $date]
        );
        $day->load('rooms.patients.vitals', 'rooms.patients.voiceNotes', 'rooms.patients.checklistItems');
        return response()->json($day);
    }
}
