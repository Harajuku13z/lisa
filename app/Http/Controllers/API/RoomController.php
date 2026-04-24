<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Day;
use App\Models\Room;
use Illuminate\Http\Request;

class RoomController extends Controller {
    public function index(Request $request, string $date) {
        $day = Day::where('user_id', $request->user()->id)->where('date', $date)->firstOrFail();
        return response()->json($day->rooms()->with('patients')->get());
    }

    public function store(Request $request, string $date) {
        $data = $request->validate(['number' => 'required|string|max:20']);
        $day = Day::firstOrCreate(['user_id' => $request->user()->id, 'date' => $date]);
        $room = $day->rooms()->create($data);
        return response()->json($room->load('patients'), 201);
    }

    public function destroy(Request $request, Room $room) {
        $this->authorizeRoom($request, $room);
        $room->delete();
        return response()->json(['message' => 'Chambre supprimée']);
    }

    private function authorizeRoom(Request $request, Room $room): void {
        if ($room->day->user_id !== $request->user()->id) abort(403);
    }
}
