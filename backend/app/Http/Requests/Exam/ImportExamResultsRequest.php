<?php

namespace App\Http\Requests\Exam;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Subir el CSV de resultados de un examen en borrador. */
class ImportExamResultsRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('import', $this->route('exam'));
    }

    public function rules(): array
    {
        return [
            // text/plain: Windows suele detectar así los CSV exportados desde Excel.
            'file' => ['required', 'file', 'mimes:csv,txt', 'extensions:csv,txt', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Adjunta el archivo CSV de resultados.',
            'file.mimes' => 'El archivo debe ser CSV (descarga la plantilla).',
            'file.extensions' => 'El archivo debe ser CSV (descarga la plantilla).',
            'file.max' => 'El archivo puede pesar como máximo 5 MB.',
        ];
    }
}
