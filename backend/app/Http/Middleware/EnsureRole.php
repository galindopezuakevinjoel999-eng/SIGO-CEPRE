<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    /**
     * Verifica que el usuario tenga uno de los roles permitidos.
     * Uso: middleware('role:admin') o middleware('role:admin,coordinador')
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => 'Acceso denegado. Se requiere uno de los roles: '.implode(', ', $roles),
            ], 403);
        }

        return $next($request);
    }
}
