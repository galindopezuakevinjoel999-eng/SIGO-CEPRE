<?php

namespace App\Http\Requests\Material;

/** Reglas comunes al subir y editar materiales. */
final class MaterialFields
{
    public static function rules(): array
    {
        return [
            'course_id' => ['nullable', 'exists:courses,id'],
            'description' => ['nullable', 'string'],
            // Solo el admin publica (ver MaterialController); a los demás se les ignora.
            'status' => ['nullable', 'in:draft,published'],
        ];
    }

    public static function messages(): array
    {
        return [
            'file.required' => 'Adjunta el archivo del material.',
            'file.file' => 'El material debe ser un archivo.',
            'file.mimes' => 'El material debe ser PDF, DOCX o PPTX.',
            'file.extensions' => 'El material debe ser PDF, DOCX o PPTX.',
            'file.max' => 'El material puede pesar como máximo 20 MB.',
        ];
    }
}
