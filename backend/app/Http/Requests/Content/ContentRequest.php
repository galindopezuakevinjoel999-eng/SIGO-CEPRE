<?php

namespace App\Http\Requests\Content;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base de las peticiones del panel de contenido (/api/admin/content/*).
 * POST crea (campos obligatorios); PUT/PATCH edita (todo opcional).
 * Autoriza con ContentPolicy (solo admin) antes de validar.
 */
abstract class ContentRequest extends FormRequest
{
    /** @return class-string modelo de contenido que se crea o edita */
    abstract protected function model(): string;

    public function authorize(): Response
    {
        return Gate::inspect($this->isCreating() ? 'create' : 'update', $this->model());
    }

    protected function isCreating(): bool
    {
        return $this->isMethod('post');
    }

    /** "required" al crear, "sometimes" al editar. */
    protected function req(): string
    {
        return $this->isCreating() ? 'required' : 'sometimes';
    }
}
