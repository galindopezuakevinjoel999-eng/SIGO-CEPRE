<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el estudiante haya completado su perfil (DNI y teléfono) antes de
 * matricularse o registrar pagos (RF06, RF09).
 *
 * Solo aplica a estudiantes: el personal no tiene que completar este perfil, y
 * lo que puede hacer o no en cada ruta lo deciden las policies.
 * Uso: middleware('profile.completed')
 */
class EnsureProfileCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isEstudiante() && ! $user->profile_completed) {
            return response()->json([
                'message' => 'Debes completar tu perfil (DNI y teléfono) antes de continuar.',
                'code' => 'profile_incomplete',
            ], 403);
        }

        return $next($request);
    }
}
