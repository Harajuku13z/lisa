<?php

namespace App\Services\AI\Agents;

use App\Models\User;
use App\Models\VitalSign;
use App\Services\AI\ExecutionContext;

class VitalSignsAgent
{
    private PatientAgent $patientAgent;

    private const ALLOWED_TYPES = [
        'temperature',
        'blood_pressure',
        'heart_rate',
        'oxygen_saturation',
        'respiratory_rate',
        'blood_glucose',
        'pain_level',
        'weight',
    ];

    public function __construct(
        private User   $user,
        private string $date
    ) {
        $this->patientAgent = new PatientAgent($user, $date);
    }

    public function handle(array $action): array
    {
        return $this->addVitalSign($action['data'] ?? [], null);
    }

    public function handleWithContext(array $action, ExecutionContext $context): array
    {
        return $this->addVitalSign($action['data'] ?? [], $context);
    }

    // ─────────────────────────────────────────────
    private function addVitalSign(array $data, ?ExecutionContext $context): array
    {
        $patientName = $data['patient_name'] ?? null;
        $roomNumber  = $data['room_number']  ?? null;
        $type        = $data['type']         ?? $data['vital_type'] ?? null;
        $value       = $data['value']        ?? null;

        if (!$type || $value === null || $value === '') {
            return ['success' => false, 'error' => 'Type ou valeur de constante manquant'];
        }

        if (!in_array($type, self::ALLOWED_TYPES)) {
            return ['success' => false, 'error' => "Type de constante inconnu : {$type}"];
        }

        $patient = $context?->getPatient($patientName)
            ?? $this->patientAgent->resolvePatient($patientName, $roomNumber);

        if (!$patient) {
            return ['success' => false, 'error' => "Patient introuvable pour la constante {$type}"];
        }

        // The VitalSign model uses one column per vital type (e.g. temperature, blood_pressure…)
        $vital = VitalSign::create([
            'patient_id' => $patient->id,
            $type        => (string) $value,
        ]);

        return [
            'success' => true,
            'vital'   => $vital,
            'patient' => $patient->name,
            'message' => "Constante {$type} = {$value} enregistrée pour {$patient->name}",
        ];
    }
}
