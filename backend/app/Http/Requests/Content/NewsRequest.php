<?php

namespace App\Http\Requests\Content;

use App\Models\Content\News;
use App\Services\PublicContentFiles;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class NewsRequest extends ContentRequest
{
    protected function model(): string
    {
        return News::class;
    }

    public function rules(): array
    {
        return [
            'title' => [$this->req(), 'string', 'max:200'],
            // Opcional: si no se envía, se genera desde el título (y no cambia al editar).
            'slug' => ['sometimes', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'summary' => ['nullable', 'string', 'max:500'],
            'content' => [$this->req(), 'string'],
            'cover_image' => ['sometimes', ...PublicContentFiles::IMAGE_RULES],
            'remove_cover_image' => ['sometimes', 'boolean'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'status' => ['sometimes', Rule::in(News::STATUSES)],
            'published_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'El slug solo puede tener minúsculas, números y guiones (ej. examen-de-admision).',
            'cover_image.mimes' => 'La portada debe ser JPG, PNG o WEBP.',
            'cover_image.extensions' => 'La portada debe ser JPG, PNG o WEBP.',
            'cover_image.max' => 'La portada puede pesar como máximo 2 MB.',
        ];
    }

    /** El slug es la URL pública de la noticia: no puede repetirse. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->filled('slug') && News::slugTaken($this->input('slug'), $this->route('id'))) {
                    $validator->errors()->add('slug', 'Ya existe una noticia con ese slug.');
                }
            },
        ];
    }
}
