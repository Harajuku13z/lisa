<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Colleague;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller {
    public function login(Request $request) {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();
        if (!$user || !Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email ou mot de passe incorrect.'],
            ]);
        }

        $token = $user->createToken('lisa-mobile')->plainTextToken;
        return response()->json(['token' => $token, 'user' => $user]);
    }

    public function register(Request $request) {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'password'          => Hash::make($data['password']),
            'email_verified_at' => now(),
        ]);

        // Auto-link any pending colleague invitations addressed to this email,
        // so existing nurses see this newcomer immediately as a linked colleague.
        Colleague::whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->whereNull('colleague_user_id')
            ->update([
                'colleague_user_id' => $user->id,
                'status'            => 'linked',
                'linked_at'         => now(),
            ]);

        $token = $user->createToken('lisa-mobile')->plainTextToken;
        return response()->json(['token' => $token, 'user' => $user], 201);
    }

    public function me(Request $request) {
        return response()->json($request->user());
    }

    public function logout(Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Déconnecté']);
    }
}
