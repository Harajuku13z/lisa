<?php
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ChecklistController;
use App\Http\Controllers\API\DayController;
use App\Http\Controllers\API\PatientController;
use App\Http\Controllers\API\RoomController;
use App\Http\Controllers\API\VitalSignController;
use App\Http\Controllers\API\VoiceNoteController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [AuthController::class, 'login']);

// Protected
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Days
    Route::get('/days', [DayController::class, 'index']);
    Route::get('/days/{date}', [DayController::class, 'show']);

    // Rooms
    Route::get('/days/{date}/rooms', [RoomController::class, 'index']);
    Route::post('/days/{date}/rooms', [RoomController::class, 'store']);
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);

    // Patients
    Route::get('/rooms/{room}/patients', [PatientController::class, 'index']);
    Route::post('/rooms/{room}/patients', [PatientController::class, 'store']);
    Route::get('/patients/{patient}', [PatientController::class, 'show']);

    // Vitals
    Route::post('/patients/{patient}/vitals', [VitalSignController::class, 'store']);

    // Voice notes
    Route::post('/voice-notes', [VoiceNoteController::class, 'store']);
    Route::post('/voice-notes/structure', [VoiceNoteController::class, 'structure']);

    // Checklist
    Route::get('/patients/{patient}/checklist', [ChecklistController::class, 'index']);
    Route::post('/patients/{patient}/generate-checklist', [ChecklistController::class, 'generate']);
    Route::put('/checklist/{checklistItem}', [ChecklistController::class, 'update']);
});
