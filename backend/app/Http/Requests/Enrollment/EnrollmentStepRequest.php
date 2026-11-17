<?php

namespace App\Http\Requests\Enrollment;

use App\Services\PrivateFiles;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del formulario de matrícula por pasos (RF10, RF12, RF13, RF14).
 *
 *  Paso 1 — Verificar perfil:  todo opcional.
 *  Paso 2 — Elegir carrera:    career_id obligatorio.
 *  Paso 3 — Subir documentos:  + documents obligatorio (archivos PDF/JPG/PNG,
 *                              máx. 5 MB c/u). Enviarlos reemplaza los anteriores.
 *  Paso 4 — Confirmación:      + classroom_id / semester_id opcionales.
 *
 * Al crear, los campos de cada paso son obligatorios. Al actualizar, solo lo son
 * si la matrícula todavía no los tiene guardados (ver requiredFor()).
 */
abstract class EnrollmentStepRequest extends FormRequest
{
    public const FIRST_STEP = 1;

    public const LAST_STEP = 4;

    public const MAX_DOCUMENTS = 10;

    /** Paso que se asume si la petición no envía "step". */
    abstract protected function defaultStep(): int;

    /** Regla de obligatoriedad para un campo exigido en el paso actual. */
    abstract protected function requiredFor(string $field): string;

    public function step(): int
    {
        return (int) $this->input('step', $this->defaultStep());
    }

    public function rules(): array
    {
        $step = $this->step();

        $rules = [
            'step' => ['sometimes', 'integer', 'between:'.self::FIRST_STEP.','.self::LAST_STEP],
            'career_id' => ['nullable', 'exists:careers,id'],
            // Archivos (multipart). Se validan en cualquier paso en que lleguen.
            'documents' => ['nullable', 'array', 'max:'.self::MAX_DOCUMENTS],
            'documents.*' => PrivateFiles::DOCUMENT_RULES,
            'progress' => ['nullable', 'array'],
        ];

        if ($step >= 2) {
            $rules['career_id'] = [$this->requiredFor('career_id'), 'exists:careers,id'];
        }

        if ($step >= 3) {
            $rules['documents'] = [$this->requiredFor('documents'), 'array', 'min:1', 'max:'.self::MAX_DOCUMENTS];
        }

        if ($step === self::LAST_STEP) {
            $rules['classroom_id'] = ['nullable', 'exists:classrooms,id'];
            $rules['semester_id'] = ['nullable', 'exists:semesters,id'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'step.between' => 'El paso de matrícula debe estar entre '.self::FIRST_STEP.' y '.self::LAST_STEP.'.',
            'career_id.required' => 'Selecciona una carrera.',
            'documents.required' => 'Adjunta los documentos solicitados.',
            'documents.max' => 'Puedes adjuntar como máximo :max documentos.',
            'documents.*.mimes' => 'Cada documento debe ser PDF, JPG o PNG.',
            'documents.*.extensions' => 'Cada documento debe ser PDF, JPG o PNG.',
            'documents.*.max' => 'Cada documento puede pesar como máximo 5 MB.',
            'documents.*.file' => 'Cada documento debe ser un archivo.',
        ];
    }

    /** Datos validados listos para guardar, con el paso actual (sin los archivos). */
    public function enrollmentData(): array
    {
        return [
            ...$this->safe()->except(['step', 'documents']),
            'current_step' => $this->step(),
        ];
    }

    /** @return list<\Illuminate\Http\UploadedFile> */
    public function documentFiles(): array
    {
        return $this->file('documents', []);
    }
}
