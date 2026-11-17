<?php

namespace App\Http\Requests\Enrollment;

use App\Models\Enrollment;
use App\Models\Semester;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Crear la solicitud de matrícula. Solo dentro del periodo de inscripción del
 * ciclo activo (Semester::isEnrollmentOpen). Quien la creó dentro del plazo
 * puede completar los pasos después.
 */
class StoreEnrollmentRequest extends EnrollmentStepRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Enrollment::class);
    }

    protected function defaultStep(): int
    {
        return self::FIRST_STEP;
    }

    protected function requiredFor(string $field): string
    {
        return 'required';
    }

    protected function hasStoredDocument(string $type): bool
    {
        return false;
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $semester = Semester::current();

                if (! $semester) {
                    $validator->errors()->add('semester', 'No hay un ciclo activo: la inscripción no está abierta.');
                } elseif (! $semester->isEnrollmentOpen()) {
                    $period = $semester->enrollmentPeriodLabel();
                    $validator->errors()->add('semester', $period
                        ? "La inscripción para el {$semester->name} es {$period}."
                        : "La inscripción para el {$semester->name} aún no tiene fechas.");
                }
            },
        ];
    }
}
