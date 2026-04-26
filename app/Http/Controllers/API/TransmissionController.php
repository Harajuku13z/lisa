<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\TransmissionMail;
use App\Models\Colleague;
use App\Models\Transmission;
use App\Models\User;
use App\Services\DaySnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;

class TransmissionController extends Controller
{
    public function __construct(private DaySnapshotService $snapshots) {}

    /**
     * POST /transmissions
     *  - mode: 'email' | 'colleague' | 'self'
     *  - source_date: YYYY-MM-DD (required)
     *  - target_date: YYYY-MM-DD (optional — defaults: same day for email, source+1 for self, today for colleague)
     *  - colleague_id: int (when mode=colleague)
     *  - to_email: string (when mode=email)
     *  - message: string (optional handoff note)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'mode'         => 'required|in:email,colleague,self',
            'source_date'  => 'required|date_format:Y-m-d',
            'target_date'  => 'nullable|date_format:Y-m-d',
            'colleague_id' => 'nullable|integer',
            'to_email'     => 'nullable|email|max:255',
            'message'      => 'nullable|string|max:2000',
        ]);

        $owner       = $request->user();
        $sourceDate  = $data['source_date'];
        $payload     = $this->snapshots->build($owner, $sourceDate);
        $message     = $data['message'] ?? null;

        if (empty($payload['rooms'])) {
            return response()->json([
                'message' => "Aucune donnée à transmettre pour le {$sourceDate}.",
            ], 422);
        }

        $mode      = $data['mode'];
        $toUserId  = null;
        $toEmail   = null;
        $targetDate = $data['target_date'] ?? $sourceDate;

        switch ($mode) {
            case 'email':
                if (empty($data['to_email'])) {
                    return response()->json(['message' => "Email du destinataire requis."], 422);
                }
                $toEmail = strtolower(trim($data['to_email']));
                break;

            case 'colleague':
                if (empty($data['colleague_id'])) {
                    return response()->json(['message' => "Collègue requis."], 422);
                }
                $colleague = Colleague::where('user_id', $owner->id)
                    ->where('id', $data['colleague_id'])
                    ->first();
                if (!$colleague || !$colleague->colleague_user_id) {
                    return response()->json([
                        'message' => "Ce collègue n'a pas encore de compte Lisa — utilisez l'envoi par e-mail.",
                    ], 422);
                }
                $toUserId   = $colleague->colleague_user_id;
                $toEmail    = $colleague->email;
                $targetDate = $data['target_date'] ?? Carbon::now()->toDateString();
                break;

            case 'self':
                $toUserId   = $owner->id;
                $toEmail    = $owner->email;
                $targetDate = $data['target_date'] ?? Carbon::parse($sourceDate)->addDay()->toDateString();
                break;
        }

        $transmission = Transmission::create([
            'from_user_id' => $owner->id,
            'to_user_id'   => $toUserId,
            'to_email'     => $toEmail,
            'mode'         => $mode,
            'source_date'  => $sourceDate,
            'target_date'  => $targetDate,
            'payload'      => $payload,
            'message'      => $message,
            'status'       => 'sent',
        ]);

        // Email mode: ship the HTML report and that's it (no in-app accept flow).
        if ($mode === 'email') {
            try {
                Mail::to($toEmail)->send(new TransmissionMail($owner, $payload, $message, $sourceDate));
                $transmission->update(['status' => 'accepted', 'accepted_at' => now()]);
            } catch (\Throwable $e) {
                Log::error('Transmission email failed', [
                    'transmission' => $transmission->id,
                    'error'        => $e->getMessage(),
                ]);
                return response()->json([
                    'transmission' => $this->present($transmission),
                    'message'      => "L'envoi par e-mail a échoué : {$e->getMessage()}",
                ], 502);
            }
        }

        return response()->json([
            'transmission' => $this->present($transmission),
        ], 201);
    }

    /** GET /transmissions/incoming — pending transmissions awaiting acceptance. */
    public function incoming(Request $request)
    {
        $items = Transmission::where('to_user_id', $request->user()->id)
            ->where('status', 'sent')
            ->whereIn('mode', ['colleague', 'self'])
            ->with('fromUser:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn(Transmission $t) => $this->present($t));

        return response()->json(['transmissions' => $items]);
    }

    /** POST /transmissions/{id}/accept — merge payload into recipient's target_date. */
    public function accept(Request $request, int $id)
    {
        $transmission = Transmission::where('to_user_id', $request->user()->id)
            ->where('id', $id)
            ->where('status', 'sent')
            ->firstOrFail();

        $stats = $this->snapshots->apply(
            $request->user(),
            $transmission->target_date->toDateString(),
            $transmission->payload
        );

        $transmission->update([
            'status'      => 'accepted',
            'accepted_at' => now(),
        ]);

        return response()->json([
            'transmission' => $this->present($transmission),
            'merged'       => $stats,
        ]);
    }

    /** POST /transmissions/{id}/decline */
    public function decline(Request $request, int $id)
    {
        $transmission = Transmission::where('to_user_id', $request->user()->id)
            ->where('id', $id)
            ->where('status', 'sent')
            ->firstOrFail();

        $transmission->update([
            'status'      => 'declined',
            'declined_at' => now(),
        ]);

        return response()->json(['transmission' => $this->present($transmission)]);
    }

    private function present(Transmission $t): array
    {
        return [
            'id'           => $t->id,
            'mode'         => $t->mode,
            'status'       => $t->status,
            'source_date'  => $t->source_date->toDateString(),
            'target_date'  => $t->target_date->toDateString(),
            'to_email'     => $t->to_email,
            'message'      => $t->message,
            'from_user'    => $t->relationLoaded('fromUser') && $t->fromUser
                ? ['id' => $t->fromUser->id, 'name' => $t->fromUser->name, 'email' => $t->fromUser->email]
                : null,
            'summary'      => [
                'rooms'    => count($t->payload['rooms'] ?? []),
                'patients' => collect($t->payload['rooms'] ?? [])
                    ->sum(fn($r) => count($r['patients'] ?? [])),
            ],
            'created_at'   => optional($t->created_at)->toIso8601String(),
            'accepted_at'  => optional($t->accepted_at)->toIso8601String(),
            'declined_at'  => optional($t->declined_at)->toIso8601String(),
        ];
    }
}
