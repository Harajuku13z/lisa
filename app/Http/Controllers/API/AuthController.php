<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
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

    public function me(Request $request) {
        return response()->json($request->user());
    }

    public function logout(Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Déconnecté']);
    }
}
