<?php

namespace App\Services\AI;

use App\Models\Patient;
use App\Models\VoiceNote;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LisaOrchestrator
{
    private ?Patient $preloadedPatient = null;
    private ?Room $preloadedRoom = null;
    private IntentParser $intentParser;
    private PlanValidator $planValidator;
    private DependencyResolver $dependencyResolver;
    private ExecutionEngine $executionEngine;
    private ResponseBuilder $responseBuilder;

    public function __construct(
        private User $user,
        private string $date
    ) {
        $this->planValidator = new PlanValidator();
        $this->dependencyResolver = new DependencyResolver();
        $this->executionEngine = new ExecutionEngine($user, $date);
        $this->responseBuilder = new ResponseBuilder();
    }

    public function preloadPatient(?int $patientId): void
    {
        if (!$patientId) {
            return;
        }

        $patient = Patient::with('assignedRoom.day')->find($patientId);
        if (!$patient || !$patient->assignedRoom || !$patient->assignedRoom->day) {
            return;
        }

        if ($patient->assignedRoom->day->user_id !== $this->user->id) {
            return;
        }

        $this->preloadedPatient = $patient;
        $this->preloadedRoom = $patient->assignedRoom;
    }

    public function preloadRoom(?int $roomId): void
    {
        if (!$roomId) {
            return;
        }

        $room = Room::with('day')->find($roomId);
        if (!$room || !$room->day || $room->day->user_id !== $this->user->id) {
            return;
        }

        $this->preloadedRoom = $room;
    }

    public function handle(string $message, string $source = 'text'): array
    {
        try {
            $this->intentParser = new IntentParser(
                $this->user,
                $this->date,
                $this->preloadedRoom,
                $this->preloadedPatient
            );

            $rawPlan = $this->intentParser->parse($message);
            $rawPlan = $this->injectContextEntities($rawPlan);
            $rawPlan = $this->completeImplicitEntities($rawPlan);
            $rawPlan = $this->completeImplicitOperations($rawPlan, $message);

            if (($rawPlan['needs_confirmation'] ?? false) === true) {
                return $this->responseBuilder->confirmation(
                    $rawPlan['confirmation_question'] ?? 'Pouvez-vous préciser votre demande ?',
                    array_values(array_filter($rawPlan['missing_information'] ?? [], 'is_string'))
                );
            }

            $validatedPlan = $this->planValidator->validate($rawPlan);
            $orderedOperations = $this->dependencyResolver->sort($validatedPlan);

            $context = new ExecutionContext(
                $this->user,
                $this->date,
                $this->preloadedRoom,
                $this->preloadedPatient
            );

            DB::transaction(function () use ($validatedPlan, $orderedOperations, $context, $message): void {
                $this->executionEngine->execute($validatedPlan, $orderedOperations, $context);
                $this->autoCreatePatientNote($validatedPlan, $message);
            });

            return $this->responseBuilder->success(
                $context,
                $validatedPlan,
                $this->buildSummary($context)
            );
        } catch (LisaFlowException $e) {
            return $this->responseBuilder->error($e->getMessage(), $e->errors);
        } catch (\Throwable $e) {
            Log::error('Lisa orchestrator fatal error', [
                'message' => $message,
                'source' => $source,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->responseBuilder->error(
                'Je n’ai pas pu terminer cette action.',
                ['server' => [$e->getMessage()]]
            );
        }
    }

    private function injectContextEntities(array $plan): array
    {
        $plan['entities'] ??= [];
        $plan['entities']['rooms'] ??= [];
        $plan['entities']['patients'] ??= [];

        $roomRefs = [];
        foreach ($plan['entities']['rooms'] as $room) {
            if (!empty($room['client_ref'])) {
                $roomRefs[$room['client_ref']] = true;
            }
        }

        if ($this->preloadedRoom && !isset($roomRefs['active_room'])) {
            $plan['entities']['rooms'][] = [
                'client_ref' => 'active_room',
                'number' => (string) $this->preloadedRoom->number,
            ];
        }

        $patientRefs = [];
        foreach ($plan['entities']['patients'] as $patient) {
            if (!empty($patient['client_ref'])) {
                $patientRefs[$patient['client_ref']] = true;
            }
        }

        if ($this->preloadedPatient && !isset($patientRefs['active_patient'])) {
            $plan['entities']['patients'][] = [
                'client_ref' => 'active_patient',
                'name' => $this->preloadedPatient->name,
                'room_ref' => 'active_room',
                'age' => $this->preloadedPatient->age,
                'gender' => $this->preloadedPatient->gender,
                'diagnosis' => $this->preloadedPatient->diagnosis,
            ];
        }

        return $plan;
    }

    private function completeImplicitEntities(array $plan): array
    {
        $plan['entities'] ??= [];
        $plan['entities']['rooms'] ??= [];
        $plan['entities']['patients'] ??= [];
        $plan['operations'] ??= [];

        $roomRefs = [];
        foreach ($plan['entities']['rooms'] as $room) {
            if (!empty($room['client_ref'])) {
                $roomRefs[$room['client_ref']] = $room;
            }
        }

        $patientRefs = [];
        foreach ($plan['entities']['patients'] as $patient) {
            if (!empty($patient['client_ref'])) {
                $patientRefs[$patient['client_ref']] = $patient;
            }
        }

        $nextOpNumber = 1;
        foreach ($plan['operations'] as $operation) {
            if (preg_match('/^op_(\d+)$/', (string) ($operation['op_id'] ?? ''), $matches)) {
                $nextOpNumber = max($nextOpNumber, ((int) $matches[1]) + 1);
            }
        }

        $defaultRoomRef = array_key_exists('active_room', $roomRefs)
            ? 'active_room'
            : (array_key_first($roomRefs) ?: null);

        $hasRoomUpsert = [];
        $hasPatientUpsert = [];
        foreach ($plan['operations'] as $operation) {
            if (($operation['type'] ?? null) === 'upsert_room' && !empty($operation['data']['room_ref'])) {
                $hasRoomUpsert[$operation['data']['room_ref']] = true;
            }
            if (($operation['type'] ?? null) === 'upsert_patient' && !empty($operation['data']['patient_ref'])) {
                $hasPatientUpsert[$operation['data']['patient_ref']] = true;
            }
        }

        foreach ($plan['operations'] as &$operation) {
            $data = $operation['data'] ?? [];

            if (!empty($data['room_ref']) && !isset($roomRefs[$data['room_ref']]) && $this->preloadedRoom) {
                if ($this->roomRefMatchesNumber($data['room_ref'], (string) $this->preloadedRoom->number)) {
                    $operation['data']['room_ref'] = 'active_room';
                    $data['room_ref'] = 'active_room';
                }
            }

            if (!empty($data['patient_ref']) && !isset($patientRefs[$data['patient_ref']])) {
                $roomRef = $data['room_ref'] ?? $defaultRoomRef;

                if ($roomRef && !isset($roomRefs[$roomRef]) && $this->preloadedRoom && $this->roomRefMatchesNumber($roomRef, (string) $this->preloadedRoom->number)) {
                    $roomRef = 'active_room';
                }

                if ($roomRef && !isset($roomRefs[$roomRef])) {
                    $roomNumber = $this->roomNumberFromRef($roomRef);
                    if ($roomNumber !== null) {
                        $roomRefs[$roomRef] = [
                            'client_ref' => $roomRef,
                            'number' => $roomNumber,
                        ];
                        $plan['entities']['rooms'][] = $roomRefs[$roomRef];
                    }
                }

                $name = $data['patient_name'] ?? $this->patientNameFromRef($data['patient_ref']);
                if ($name && $roomRef) {
                    $patientRefs[$data['patient_ref']] = [
                        'client_ref' => $data['patient_ref'],
                        'name' => $name,
                        'room_ref' => $roomRef,
                        'age' => null,
                        'gender' => null,
                        'diagnosis' => null,
                    ];
                    $plan['entities']['patients'][] = $patientRefs[$data['patient_ref']];
                }
            }
        }
        unset($operation);

        foreach ($roomRefs as $roomRef => $room) {
            if ($roomRef === 'active_room' || isset($hasRoomUpsert[$roomRef])) {
                continue;
            }

            $plan['operations'][] = [
                'op_id' => 'op_' . $nextOpNumber++,
                'type' => 'upsert_room',
                'depends_on' => [],
                'data' => [
                    'room_ref' => $roomRef,
                ],
            ];
            $hasRoomUpsert[$roomRef] = true;
        }

        foreach ($patientRefs as $patientRef => $patient) {
            if ($patientRef === 'active_patient' || isset($hasPatientUpsert[$patientRef])) {
                continue;
            }

            $dependsOn = [];
            if (!empty($patient['room_ref']) && isset($hasRoomUpsert[$patient['room_ref']])) {
                foreach ($plan['operations'] as $operation) {
                    if (($operation['type'] ?? null) === 'upsert_room' && ($operation['data']['room_ref'] ?? null) === $patient['room_ref']) {
                        $dependsOn[] = $operation['op_id'];
                        break;
                    }
                }
            }

            $plan['operations'][] = [
                'op_id' => 'op_' . $nextOpNumber++,
                'type' => 'upsert_patient',
                'depends_on' => $dependsOn,
                'data' => [
                    'patient_ref' => $patientRef,
                    'room_ref' => $patient['room_ref'],
                ],
            ];
            $hasPatientUpsert[$patientRef] = true;
        }

        return $plan;
    }

    /**
     * The model proposes the plan, then the backend completes obvious clinical
     * operations. This prevents a valid room/patient/checklist flow from
     * silently missing a vital sign like "Robert a 30°".
     */
    private function completeImplicitOperations(array $plan, string $message): array
    {
        $temperature = $this->extractTemperature($message);
        if ($temperature === null) {
            return $plan;
        }

        $patientRef = $this->patientRefForTemperature($plan, $message);
        if ($patientRef === null) {
            return $plan;
        }

        foreach ($plan['operations'] ?? [] as $operation) {
            $data = $operation['data'] ?? [];
            $type = $operation['type'] ?? null;

            if ($type === 'add_vital'
                && ($data['patient_ref'] ?? null) === $patientRef
                && $this->normalizeVitalTypeForComparison((string) ($data['type'] ?? '')) === 'temperature'
            ) {
                return $plan;
            }
        }

        $nextOpNumber = $this->nextOperationNumber($plan['operations'] ?? []);
        $dependsOn = $this->dependencyForPatientRef($plan['operations'] ?? [], $patientRef);

        $plan['operations'][] = [
            'op_id' => 'op_' . $nextOpNumber,
            'type' => 'add_vital',
            'depends_on' => $dependsOn,
            'data' => [
                'patient_ref' => $patientRef,
                'type' => 'temperature',
                'value' => $temperature,
                'unit' => '°C',
            ],
        ];

        return $plan;
    }

    private function extractTemperature(string $message): ?float
    {
        $normalized = mb_strtolower($message);

        $patterns = [
            '/temp[ée]rature\s*(?:est|à|a|de|:)?\s*(?:de\s*)?(\d+(?:[,.]\d+)?)/u',
            '/(\d+(?:[,.]\d+)?)\s*(?:°\s*c?|degr[ée]s?)\s*(?:de\s*)?temp[ée]rature/u',
            '/(?:a|à|avec|présente)\s+(\d+(?:[,.]\d+)?)\s*(?:°\s*c?|degr[ée]s?)(?!\s*(?:ans|an))/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                return (float) str_replace(',', '.', $matches[1]);
            }
        }

        return null;
    }

    private function patientRefForTemperature(array $plan, string $message): ?string
    {
        $patients = $plan['entities']['patients'] ?? [];

        if ($this->preloadedPatient) {
            return 'active_patient';
        }

        if (count($patients) === 1 && !empty($patients[0]['client_ref'])) {
            return (string) $patients[0]['client_ref'];
        }

        $normalizedMessage = $this->normalizeText($message);
        foreach ($patients as $patient) {
            $name = $this->normalizeText((string) ($patient['name'] ?? ''));
            if ($name !== '' && str_contains($normalizedMessage, $name) && !empty($patient['client_ref'])) {
                return (string) $patient['client_ref'];
            }
        }

        return null;
    }

    private function dependencyForPatientRef(array $operations, string $patientRef): array
    {
        if ($patientRef === 'active_patient') {
            return [];
        }

        foreach ($operations as $operation) {
            if (($operation['type'] ?? null) === 'upsert_patient'
                && ($operation['data']['patient_ref'] ?? null) === $patientRef
                && !empty($operation['op_id'])
            ) {
                return [(string) $operation['op_id']];
            }
        }

        return [];
    }

    private function nextOperationNumber(array $operations): int
    {
        $next = 1;
        foreach ($operations as $operation) {
            if (preg_match('/^op_(\d+)$/', (string) ($operation['op_id'] ?? ''), $matches)) {
                $next = max($next, ((int) $matches[1]) + 1);
            }
        }

        return $next;
    }

    private function normalizeVitalTypeForComparison(string $type): string
    {
        return match (mb_strtolower(trim($type))) {
            'temp', 'température', 'temperature' => 'temperature',
            default => mb_strtolower(trim($type)),
        };
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['’', "'"], ' ', $text);
        $text = preg_replace('/[^\pL\pN]+/u', ' ', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function patientNameFromRef(string $patientRef): ?string
    {
        $normalized = preg_replace('/^patient_/', '', trim($patientRef));
        $normalized = str_replace('_', ' ', (string) $normalized);
        $normalized = trim($normalized);

        if ($normalized === '') {
            return null;
        }

        return mb_convert_case($normalized, MB_CASE_TITLE, 'UTF-8');
    }

    private function roomNumberFromRef(string $roomRef): ?string
    {
        if (preg_match('/(\d+)/', $roomRef, $matches)) {
            return (string) (int) $matches[1];
        }

        return null;
    }

    private function roomRefMatchesNumber(string $roomRef, string $roomNumber): bool
    {
        $derived = $this->roomNumberFromRef($roomRef);
        return $derived !== null && $derived === (string) (int) $roomNumber;
    }

    private function buildSummary(ExecutionContext $context): string
    {
        $parts = [];

        if ($context->created['rooms'] > 0) {
            $parts[] = $context->created['rooms'] . ' chambre' . ($context->created['rooms'] > 1 ? 's' : '');
        }

        if ($context->created['patients'] > 0) {
            $parts[] = $context->created['patients'] . ' patient' . ($context->created['patients'] > 1 ? 's' : '');
        }

        if ($context->created['vitals'] > 0) {
            $parts[] = $context->created['vitals'] . ' constante' . ($context->created['vitals'] > 1 ? 's' : '');
        }

        if ($context->created['appointments'] > 0) {
            $parts[] = $context->created['appointments'] . ' rendez-vous';
        }

        if ($context->created['checklist_items'] > 0) {
            $parts[] = $context->created['checklist_items'] . ' checklist' . ($context->created['checklist_items'] > 1 ? 's' : '');
        }

        if ($context->created['notes'] > 0) {
            $parts[] = $context->created['notes'] . ' note' . ($context->created['notes'] > 1 ? 's' : '');
        }

        if (empty($parts)) {
            return 'Aucune modification à enregistrer.';
        }

        return 'Lisa a enregistré : ' . implode(', ', $parts) . '.';
    }

    /**
     * When the user dictates from a patient view (preloadedPatient set), guarantee that
     * the original message is saved as a note attached to the patient — even if the AI
     * plan didn't include a create_note op. This preserves the dictation as a clinical
     * trace alongside any structured action (vital, appointment, etc.).
     */
    private function autoCreatePatientNote(array $plan, string $message): void
    {
        if (!$this->preloadedPatient) {
            return;
        }

        $trimmed = trim($message);
        if ($trimmed === '') {
            return;
        }

        // Skip if the plan already produced a note for this patient — avoid duplicates.
        foreach ($plan['operations'] ?? [] as $operation) {
            if (($operation['type'] ?? null) === 'create_note') {
                $ref = $operation['data']['patient_ref'] ?? null;
                if ($ref === 'active_patient' || $ref === null) {
                    return;
                }
            }
        }

        VoiceNote::create([
            'user_id' => $this->user->id,
            'patient_id' => $this->preloadedPatient->id,
            'raw_text' => $trimmed,
            'structured_text' => $trimmed,
        ]);
    }

}
