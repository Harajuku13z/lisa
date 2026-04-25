<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;

class VitalSignController extends Controller {
    public function store(Request $request, Patient $patient) {
        if (!$patient->assignedRoom || !$patient->assignedRoom->day) abort(404);
        if ($patient->assignedRoom->day->user_id !== $request->user()->id) abort(403);
        $data = $request->validate([
            'temperature'       => 'nullable|numeric|min:0|max:50',
            'blood_pressure'    => 'nullable|string|max:20',
            'heart_rate'        => 'nullable|integer|min:0|max:300',
            'oxygen_saturation' => 'nullable|numeric|min:0|max:100',
            'notes'             => 'nullable|string',
        ]);
        $vital = $patient->vitals()->create($data);
        return response()->json($vital, 201);
    }
}
