<?php

namespace App\Http\Requests\Material;

use App\Services\PrivateFiles;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Editar un material. Un archivo nuevo reemplaza al anterior. */
class UpdateMaterialRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('material'));
    }

    public function rules(): array
    {
        return [
            'file' => ['sometimes', ...PrivateFiles::MATERIAL_RULES],
            ...MaterialFields::rules(),
            'title' => ['sometimes', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return MaterialFields::messages();
    }
}
