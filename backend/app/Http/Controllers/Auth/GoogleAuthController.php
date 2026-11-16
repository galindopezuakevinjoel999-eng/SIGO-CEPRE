<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Inicio de sesión con Google OAuth (RF01, RF02, RNF08).
 *
 * Flujo:
 *  1. GET  /api/auth/google            → redirige a Google.
 *  2. GET  /api/auth/google/callback   → Google vuelve aquí; se busca o crea el
 *     usuario y se redirige al frontend con un código de un solo uso (no el token).
 *  3. POST /api/auth/google/exchange   → el frontend canjea el código por el
 *     token de Sanctum. Así el token nunca viaja en la URL (historial, logs, Referer).
 */
class GoogleAuthController extends Controller
{
    /** Segundos de validez del código de intercambio. */
    public const EXCHANGE_CODE_TTL = 60;

    private const CACHE_PREFIX = 'google_auth_code:';

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            Log::warning('Fallo en el callback de Google OAuth', ['exception' => $e]);

            return $this->redirectToFrontend(['error' => 'google_failed']);
        }

        if (! $this->emailIsVerified($googleUser)) {
            return $this->redirectToFrontend(['error' => 'email_not_verified']);
        }

        $user = $this->findOrCreateUser($googleUser);

        if ($error = $this->loginBlockReason($user)) {
            return $this->redirectToFrontend(['error' => $error]);
        }

        $code = Str::random(64);
        Cache::put(self::CACHE_PREFIX.$code, $user->id, self::EXCHANGE_CODE_TTL);

        return $this->redirectToFrontend(['code' => $code]);
    }

    public function exchange(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:64'],
        ]);

        // pull = leer y borrar: el código solo puede usarse una vez.
        $userId = Cache::pull(self::CACHE_PREFIX.$data['code']);
        $user = $userId ? User::find($userId) : null;

        if (! $user) {
            return response()->json(['message' => 'El código de inicio de sesión es inválido o expiró.'], 422);
        }

        // Se vuelve a validar por si la cuenta cambió entre el callback y el canje.
        if ($this->loginBlockReason($user)) {
            return response()->json(['message' => 'Tu cuenta no tiene acceso al sistema.'], 403);
        }

        return response()->json([
            'token' => $user->createToken('google')->plainTextToken,
            'user' => $user,
        ]);
    }

    /**
     * Busca primero por google_id y luego por email (vincula cuentas creadas por el
     * administrador). Si no existe, lo registra como estudiante (RF02, RF05).
     */
    private function findOrCreateUser(SocialiteUser $googleUser): User
    {
        $user = User::where('google_id', $googleUser->getId())->first()
            ?? User::where('email', $googleUser->getEmail())->first();

        if (! $user) {
            return User::create([
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'email' => $googleUser->getEmail(),
                'password' => Str::random(40), // inutilizable: los estudiantes entran solo con Google
                'role' => User::ROLE_ESTUDIANTE,
                'status' => 'active',
                'google_id' => $googleUser->getId(),
                'photo_url' => $googleUser->getAvatar(),
            ]);
        }

        $user->google_id ??= $googleUser->getId();
        $user->photo_url ??= $googleUser->getAvatar();
        $user->save();

        return $user;
    }

    private function emailIsVerified(SocialiteUser $googleUser): bool
    {
        $raw = method_exists($googleUser, 'getRaw') ? $googleUser->getRaw() : [];

        // Google envía "email_verified" (OpenID) o "verified_email" (API v2).
        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return filter_var($verified, FILTER_VALIDATE_BOOLEAN) && filled($googleUser->getEmail());
    }

    /** Devuelve el motivo por el que el usuario no puede entrar, o null si puede. */
    private function loginBlockReason(User $user): ?string
    {
        if ($user->status !== 'active') {
            return 'account_inactive';
        }

        if ($user->student_status === 'inhabilitado') {
            return 'account_disabled';
        }

        return null;
    }

    private function redirectToFrontend(array $query): RedirectResponse
    {
        $url = rtrim(config('app.frontend_url'), '/').'/auth/callback?'.http_build_query($query);

        return redirect()->away($url);
    }
}
