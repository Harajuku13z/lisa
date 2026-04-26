<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\ColleagueInviteMail;
use App\Models\Colleague;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ColleagueController extends Controller
{
    /** GET /colleagues — list of colleagues for the authenticated nurse. */
    public function index(Request $request)
    {
        $colleagues = Colleague::where('user_id', $request->user()->id)
            ->orderByRaw("status = 'linked' DESC")
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn(Colleague $c) => $this->present($c));

        return response()->json(['colleagues' => $colleagues]);
    }

    /**
     * POST /colleagues  { email, name? }
     * If a Lisa user already exists with this email → link both sides.
     * Otherwise → save as pending_signup and send an invitation email.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'name'  => 'nullable|string|max:120',
        ]);

        $owner = $request->user();
        $email = strtolower(trim($data['email']));

        if ($email === strtolower($owner->email)) {
            return response()->json([
                'message' => "C'est votre propre adresse — choisissez un collègue.",
            ], 422);
        }

        $existing = Colleague::where('user_id', $owner->id)
            ->where('email', $email)
            ->first();
        if ($existing) {
            return response()->json([
                'message'   => 'Ce collègue est déjà dans votre liste.',
                'colleague' => $this->present($existing),
            ], 200);
        }

        $colleagueUser = User::whereRaw('LOWER(email) = ?', [$email])->first();

        $colleague = Colleague::create([
            'user_id'           => $owner->id,
            'colleague_user_id' => $colleagueUser?->id,
            'email'             => $email,
            'name'              => $data['name'] ?? $colleagueUser?->name,
            'status'            => $colleagueUser ? 'linked' : 'pending_signup',
            'invited_at'        => now(),
            'linked_at'         => $colleagueUser ? now() : null,
        ]);

        if (!$colleagueUser) {
            // Account does not exist → send invite email saying so.
            try {
                Mail::to($email)->send(new ColleagueInviteMail($owner, $email));
            } catch (\Throwable $e) {
                Log::warning('Colleague invite email failed', [
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'colleague' => $this->present($colleague),
            'invited'   => $colleagueUser === null,
        ], 201);
    }

    /** DELETE /colleagues/{id} */
    public function destroy(Request $request, int $id)
    {
        $colleague = Colleague::where('user_id', $request->user()->id)->findOrFail($id);
        $colleague->delete();
        return response()->json(['ok' => true]);
    }

    private function present(Colleague $c): array
    {
        return [
            'id'         => $c->id,
            'email'      => $c->email,
            'name'       => $c->name,
            'status'     => $c->status,
            'invited_at' => optional($c->invited_at)->toIso8601String(),
            'linked_at'  => optional($c->linked_at)->toIso8601String(),
        ];
    }
}
