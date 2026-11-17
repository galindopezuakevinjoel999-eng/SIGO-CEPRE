<?php

namespace App\Policies;

use App\Models\Exam;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Exámenes y resultados (RF30–RF34).
 *  - Admin: crea, importa resultados, publica y elimina.
 *  - Coordinador: ve exámenes y rankings.
 *  - Estudiante: solo sus propios resultados de exámenes publicados
 *    (ExamResultController::mine).
 * Un examen publicado queda fijo: no se edita, reimporta ni elimina.
 */
class ExamPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isCoordinador();
    }

    public function view(User $user, Exam $exam): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): Response
    {
        return $this->admin($user);
    }

    public function update(User $user, Exam $exam): Response
    {
        return $this->admin($user)->allowed() ? $this->draft($exam) : $this->admin($user);
    }

    public function delete(User $user, Exam $exam): Response
    {
        return $this->update($user, $exam);
    }

    public function import(User $user, Exam $exam): Response
    {
        return $this->update($user, $exam);
    }

    public function publish(User $user, Exam $exam): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny('Solo administración puede publicar resultados.');
        }

        return $exam->isPublished()
            ? Response::deny('Los resultados de este examen ya están publicados.')
            : Response::allow();
    }

    private function admin(User $user): Response
    {
        return $user->isAdmin() ? Response::allow() : Response::deny('Solo administración puede gestionar exámenes.');
    }

    private function draft(Exam $exam): Response
    {
        return $exam->isPublished()
            ? Response::deny('El examen ya está publicado: sus resultados no se pueden modificar.')
            : Response::allow();
    }
}
