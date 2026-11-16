<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;

class GoogleAuthController extends Controller
{
    // Redirigir a Google
    public function redirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    // Callback de Google
    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            // Buscar usuario por email
            $user = User::where('email', $googleUser->getEmail())->first();

            if (!$user) {
                // Crear usuario nuevo
                $user = User::create([
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => bcrypt(uniqid()),
                    'role' => User::ROLE_ESTUDIANTE,
                ]);
            }

            if ($user->student_status === 'inhabilitado') {
                return response()->json([
                    'message' => 'Tu cuenta está inhabilitada y no puedes iniciar sesión.',
                ], 403);
            }

            // Crear token con Sanctum
            $token = $user->createToken('auth_token')->plainTextToken;

            // 🔥 REDIRECCIÓN AL FRONTEND (Next.js)
            return redirect("http://localhost:3000/auth/callback?token=$token");

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error en autenticación',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}