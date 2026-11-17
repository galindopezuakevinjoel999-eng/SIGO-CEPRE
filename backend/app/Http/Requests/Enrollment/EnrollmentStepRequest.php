<?php

namespace App\Http\Requests\Enrollment;

use App\Models\Enrollment;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del formulario de matrícula por pasos (RF10, RF12, RF13, RF14).
 *
 *  Paso 1 — Verificar perfil:  todo opcional.
 *  Paso 2 — Elegir carrera:    career_id obligatorio.
 *  Paso 3 — Subir documentos:  documentos por tipo (Enrollment::DOCUMENT_TYPES), máx. 5 MB c/u:
 *                                recibo_pago      JPG/PNG/PDF  obligatorio
 *                                foto_postulante  JPG/PNG      obligatorio (se usa en el carnet)
 *                                dni              PDF          obligatorio
 *                                certificado_estudios PDF      opcional: si falta, la matrícula
 *                                                 queda con el certificado pendiente de regularizar.
 *                              Se envían como documents[tipo]; enviar uno reemplaza el anterior
 *                              de ese tipo y conserva los demás.
 *  Paso 4 — Confirmación:      + classroom_id / semester_id opcionales.
 *
 * Al crear, lo exigido en cada paso es obligatorio. Al actualizar, solo si la
 * matrícula todavía no lo tiene guardado.
 */
abstract class EnrollmentStepRequest extends FormRequest
{
    public const FIRST_STEP = 1;

    public const LAST_STEP = 4;

    public const MAX_DOCUMENT_KB = 5120;

    /** Paso que se asume si la petición no envía "step". */
    abstract protected function defaultStep(): int;

    /** Regla de obligatoriedad para un campo exigido en el paso actual. */
    abstract protected function requiredFor(string $field): string;

    /** ¿La matrícula ya tiene guardado un documento de ese tipo? */
    abstract protected function hasStoredDocument(string $type): bool;

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
            // documents[tipo] (multipart). Solo se aceptan los tipos conocidos.
            'documents' => ['nullable', 'array:'.implode(',', array_keys(Enrollment::DOCUMENT_TYPES))],
            'progress' => ['nullable', 'array'],
        ];

        // Cada documento se valida en cualquier paso en que llegue.
        foreach (Enrollment::DOCUMENT_TYPES as $type => $spec) {
            $required = $step >= 3 && $spec['required'] && ! $this->hasStoredDocument($type) ? 'required' : 'sometimes';
            $extensions = implode(',', $spec['extensions']);

            $rules["documents.{$type}"] = [$required, 'file', "mimes:{$extensions}", "extensions:{$extensions}", 'max:'.self::MAX_DOCUMENT_KB];
        }

        if ($step >= 2) {
            $rules['career_id'] = [$this->requiredFor('career_id'), 'exists:careers,id'];
        }

        if ($step === self::LAST_STEP) {
            $rules['classroom_id'] = ['nullable', 'exists:classrooms,id'];
            $rules['semester_id'] = ['nullable', 'exists:semesters,id'];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [
            'step.between' => 'El paso de matrícula debe estar entre '.self::FIRST_STEP.' y '.self::LAST_STEP.'.',
            'career_id.required' => 'Selecciona una carrera.',
            'documents.array' => 'Tipo de documento desconocido. Usa: '.implode(', ', array_keys(Enrollment::DOCUMENT_TYPES)).'.',
        ];

        foreach (Enrollment::DOCUMENT_TYPES as $type => $spec) {
            $formats = strtoupper(implode('/', array_diff($spec['extensions'], ['jpeg'])));
            $messages["documents.{$type}.required"] = "Adjunta: {$spec['label']}.";
            $messages["documents.{$type}.file"] = "{$spec['label']}: debe ser un archivo.";
            $messages["documents.{$type}.mimes"] = "{$spec['label']}: el formato debe ser {$formats}.";
            $messages["documents.{$type}.extensions"] = "{$spec['label']}: el formato debe ser {$formats}.";
            $messages["documents.{$type}.max"] = "{$spec['label']}: puede pesar como máximo 5 MB.";
        }

        return $messages;
    }

    /** Datos validados listos para guardar, con el paso actual (sin los archivos). */
    public function enrollmentData(): array
    {
        return [
            ...$this->safe()->except(['step', 'documents']),
            'current_step' => $this->step(),
        ];
    }

    /** @return array<string, \Illuminate\Http\UploadedFile> tipo => archivo */
    public function documentFiles(): array
    {
        return array_filter($this->file('documents', []), fn ($file, $type) => isset(Enrollment::DOCUMENT_TYPES[$type]), ARRAY_FILTER_USE_BOTH);
    }
}
