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

        // Auto-generate initials and sync full_name for legacy DB compatibility
        $data['full_name'] = $data['name'];
        $data['initials']  = $this->makeInitials($data['name']);

        $patient = $room->patients()->create($data);
        return response()->json($patient->load('vitals', 'voiceNotes', 'checklistItems'), 201);
    }

    private function makeInitials(string $name): string {
        $words = array_filter(explode(' ', mb_strtoupper(trim($name))));
        if (count($words) >= 2) {
            return mb_substr(array_shift($words), 0, 1) . mb_substr(array_shift($words), 0, 1);
        }
        return mb_substr($name, 0, 2, 'UTF-8');
    }

    public function show(Request $request, Patient $patient) {
        $this->authorizePatient($request, $patient);
        return response()->json($patient->load('vitals', 'voiceNotes', 'checklistItems'));
    }

    public function destroy(Request $request, Patient $patient) {
        $this->authorizePatient($request, $patient);
        $patient->delete();
        return response()->json(['message' => 'Patient supprimé'], 200);
    }

    private function authorizeRoom(Request $request, Room $room): void {
        if ($room->day->user_id !== $request->user()->id) abort(403);
    }

    private function authorizePatient(Request $request, Patient $patient): void {
        $room = $patient->assignedRoom;
        if (!$room || !$room->day) abort(404);
        if ($room->day->user_id !== $request->user()->id) abort(403);
    }
}
