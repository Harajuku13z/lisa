<?php
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ChecklistController;
use App\Http\Controllers\API\DayController;
use App\Http\Controllers\API\LisaAIController;
use App\Http\Controllers\API\PatientController;
use App\Http\Controllers\API\PatientNoteController;
use App\Http\Controllers\API\RoomController;
use App\Http\Controllers\API\RoomVoiceController;
use App\Http\Controllers\API\VitalSignController;
use App\Http\Controllers\API\VoiceNoteController;
use Illuminate\Support\Facades\Route;

// Public
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

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
    Route::post('/rooms/{room}/voice-process', [RoomVoiceController::class, 'process']);

    // Patients
    Route::get('/rooms/{room}/patients', [PatientController::class, 'index']);
    Route::post('/rooms/{room}/patients', [PatientController::class, 'store']);
    Route::get('/patients/{patient}', [PatientController::class, 'show']);
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy']);

    // Vitals
    Route::post('/patients/{patient}/vitals', [VitalSignController::class, 'store']);

    // AI note processing (creates note + vitals + checklist in one call)
    Route::post('/patients/{patient}/process-note', [PatientNoteController::class, 'process']);

    // Voice notes
    Route::post('/voice-notes', [VoiceNoteController::class, 'store']);
    Route::post('/voice-notes/structure', [VoiceNoteController::class, 'structure']);

    // Lisa multi-agent AI orchestrator
    Route::post('/lisa/message', [LisaAIController::class, 'message']);

    // Checklist
    Route::get('/patients/{patient}/checklist', [ChecklistController::class, 'index']);
    Route::post('/patients/{patient}/checklist', [ChecklistController::class, 'store']);
    Route::post('/patients/{patient}/generate-checklist', [ChecklistController::class, 'generate']);
    Route::put('/checklist/{checklistItem}', [ChecklistController::class, 'update']);
});
