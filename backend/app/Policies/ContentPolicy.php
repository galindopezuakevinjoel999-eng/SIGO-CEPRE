<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Contenido público en MongoDB (noticias, comunicados, FAQ, bloques del inicio).
 * Lo gestiona solo el administrador; la lectura pública no pasa por aquí
 * (PublicController no exige sesión). Registrada en AppServiceProvider.
 */
class ContentPolicy
{
    public function before(User $user): ?Response
    {
        return $user->isAdmin() ? Response::allow() : Response::deny('Solo administración puede gestionar el contenido público.');
    }

    public function viewAny(User $user): bool
    {
        return false; // nunca se llega: before() decide
    }

    public function view(User $user): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }
}
