<?php

namespace App\Policies;

use App\Models\Material;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quién puede hacer qué con un material de estudio (RF27–RF29).
 *  - Estudiante: ve y descarga los publicados, solo si tiene matrícula aprobada
 *    en el semestre activo.
 *  - Docente: sube materiales (quedan en borrador) y edita los suyos.
 *  - Admin: todo, incluido publicar y eliminar.
 *  - Coordinador: ve y descarga todos.
 */
class MaterialPolicy
{
    public function viewAny(User $user): Response
    {
        if ($user->isEstudiante()) {
            return $user->isEnrolledInCurrentSemester()
                ? Response::allow()
                : Response::deny('Solo estudiantes con matrícula aprobada en el semestre actual pueden ver materiales.');
        }

        return Response::allow();
    }

    /** ¿Ve también los borradores? */
    public function viewDrafts(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Material $material): Response
    {
        if ($user->isStaff()) {
            return Response::allow();
        }

        if (! $material->isPublished()) {
            return Response::denyAsNotFound();
        }

        return $this->viewAny($user);
    }

    public function download(User $user, Material $material): Response
    {
        return $this->view($user, $material);
    }

    public function create(User $user): Response
    {
        return $user->isAdmin() || $user->isDocente()
            ? Response::allow()
            : Response::deny('Solo administración y docentes pueden subir materiales.');
    }

    public function update(User $user, Material $material): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        return $user->isDocente() && (int) $material->uploaded_by === (int) $user->id
            ? Response::allow()
            : Response::deny('Solo el docente autor o administración pueden modificar este material.');
    }

    /** Cambiar el estado borrador/publicado. */
    public function publish(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Material $material): Response
    {
        return $user->isAdmin()
            ? Response::allow()
            : Response::deny('Solo administración puede eliminar materiales.');
    }
}
