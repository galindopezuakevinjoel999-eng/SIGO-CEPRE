<?php

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quién puede hacer qué con una matrícula (RNF11).
 *  - Estudiante: crea, ve y edita solo las suyas; edita o elimina solo si están pendientes.
 *  - Admin y coordinador: ven todas y aprueban o rechazan.
 *  - Admin: además puede eliminar matrículas pendientes.
 *  - Docente: sin acceso.
 */
class EnrollmentPolicy
{
    /** ¿Ve las matrículas de todos los estudiantes? (si no, solo las propias) */
    public function viewAll(User $user): bool
    {
        return $user->isAdmin() || $user->isCoordinador();
    }

    public function view(User $user, Enrollment $enrollment): Response
    {
        return $this->viewAll($user) || $this->owns($user, $enrollment)
            ? Response::allow()
            : Response::deny('No tienes acceso a esta matrícula.');
    }

    public function create(User $user): Response
    {
        return $user->isEstudiante()
            ? Response::allow()
            : Response::deny('Solo los estudiantes pueden solicitar matrícula.');
    }

    public function update(User $user, Enrollment $enrollment): Response
    {
        if (! $this->owns($user, $enrollment)) {
            return Response::deny('No tienes acceso a esta matrícula.');
        }

        return $enrollment->status === 'pending'
            ? Response::allow()
            : Response::deny('No puedes editar una matrícula que ya fue procesada.');
    }

    public function delete(User $user, Enrollment $enrollment): Response
    {
        if (! $this->owns($user, $enrollment) && ! $user->isAdmin()) {
            return Response::deny('No tienes acceso a esta matrícula.');
        }

        return $enrollment->status === 'pending'
            ? Response::allow()
            : Response::deny('Solo se pueden eliminar matrículas pendientes.');
    }

    /** Aprobar o rechazar (RF16). */
    public function approve(User $user, Enrollment $enrollment): Response
    {
        return $this->viewAll($user)
            ? Response::allow()
            : Response::deny('Solo administración o coordinación pueden aprobar o rechazar matrículas.');
    }

    private function owns(User $user, Enrollment $enrollment): bool
    {
        return (int) $enrollment->student_id === (int) $user->id;
    }
}
