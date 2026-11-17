<?php

namespace App\Http\Requests\Material;

use App\Models\Material;
use App\Services\PrivateFiles;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Subir un material (RF27, RF28): archivo PDF, DOCX o PPTX de hasta 20 MB. */
class StoreMaterialRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Material::class);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', ...PrivateFiles::MATERIAL_RULES],
            ...MaterialFields::rules(),
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return MaterialFields::messages();
    }
}
