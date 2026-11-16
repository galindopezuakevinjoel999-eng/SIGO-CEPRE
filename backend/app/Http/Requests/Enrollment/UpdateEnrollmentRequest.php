<?php

namespace App\Http\Requests\Enrollment;

use App\Models\Enrollment;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class UpdateEnrollmentRequest extends EnrollmentStepRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->enrollment());
    }

    protected function defaultStep(): int
    {
        return $this->enrollment()->current_step ?? self::FIRST_STEP;
    }

    /**
     * Si la matrícula ya tiene el dato de un paso anterior, no hace falta reenviarlo;
     * si se envía, debe ser válido (sin "nullable" no se puede borrar con null).
     */
    protected function requiredFor(string $field): string
    {
        return blank($this->enrollment()->{$field}) ? 'required' : 'sometimes';
    }

    private function enrollment(): Enrollment
    {
        return $this->route('enrollment');
    }
}
