<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Quién puede hacer qué con un pago (RNF11).
 *  - Estudiante: registra pagos y ve los suyos; edita o elimina solo los pendientes.
 *  - Admin y coordinador: ven todos y validan (RF21).
 *  - Admin: además puede editar o eliminar cualquier pago.
 *  - Docente: sin acceso.
 */
class PaymentPolicy
{
    /** ¿Ve los pagos de todos los estudiantes? (si no, solo los propios) */
    public function viewAll(User $user): bool
    {
        return $user->isAdmin() || $user->isCoordinador();
    }

    public function view(User $user, Payment $payment): Response
    {
        return $this->viewAll($user) || $this->owns($user, $payment)
            ? Response::allow()
            : Response::deny('No tienes acceso a este pago.');
    }

    public function create(User $user): Response
    {
        return $user->isEstudiante()
            ? Response::allow()
            : Response::deny('Solo los estudiantes pueden registrar pagos.');
    }

    public function update(User $user, Payment $payment): Response
    {
        return $this->modify($user, $payment);
    }

    public function delete(User $user, Payment $payment): Response
    {
        return $this->modify($user, $payment);
    }

    /** Validar el pago, es decir, cambiar su estado (RF21). */
    public function validate(User $user, Payment $payment): Response
    {
        return $this->viewAll($user)
            ? Response::allow()
            : Response::deny('Solo administración o coordinación pueden validar pagos.');
    }

    private function modify(User $user, Payment $payment): Response
    {
        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $this->owns($user, $payment)) {
            return Response::deny('No tienes acceso a este pago.');
        }

        return $payment->status === 'pending'
            ? Response::allow()
            : Response::deny('No puedes modificar un pago que ya fue validado.');
    }

    private function owns(User $user, Payment $payment): bool
    {
        return (int) $payment->student_id === (int) $user->id;
    }
}
