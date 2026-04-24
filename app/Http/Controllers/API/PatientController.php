<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\Room;
use Illuminate\Http\Request;

class PatientController extends Controller {
    public function index(Request $request, Room $room) {
        $this->authorizeRoom($request, $room);
        return response()->json($room->patients()->with('vitals', 'voiceNotes', 'checklistItems')->get());
    }

    public function store(Request $request, Room $room) {
        $this->authorizeRoom($request, $room);
        $data = $request->validate([
            'name'      => 'required|string|max:255',
            'age'       => 'nullable|integer|min:0|max:150',
            'gender'    => 'nullable|string|max:5',
            'diagnosis' => 'nullable|string',
        ]);
        $patient = $room->patients()->create($data);
        return response()->json($patient->load('vitals', 'voiceNotes', 'checklistItems'), 201);
    }

    public function show(Request $request, Patient $patient) {
        $this->authorizePatient($request, $patient);
        return response()->json($patient->load('vitals', 'voiceNotes', 'checklistItems'));
    }

    private function authorizeRoom(Request $request, Room $room): void {
        if ($room->day->user_id !== $request->user()->id) abort(403);
    }

    private function authorizePatient(Request $request, Patient $patient): void {
        if ($patient->room->day->user_id !== $request->user()->id) abort(403);
    }
}
