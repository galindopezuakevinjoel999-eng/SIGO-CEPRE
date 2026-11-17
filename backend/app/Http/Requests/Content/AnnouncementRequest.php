<?php

namespace App\Http\Requests\Content;

use App\Models\Career;
use App\Models\Content\Announcement;
use App\Services\PublicContentFiles;
use Illuminate\Validation\Rule;

class AnnouncementRequest extends ContentRequest
{
    public const MAX_ATTACHMENTS = 5;

    protected function model(): string
    {
        return Announcement::class;
    }

    /** Grupos y códigos de carrera en mayúsculas antes de validar ("a" → "A"). */
    protected function prepareForValidation(): void
    {
        foreach (['groups', 'career_codes'] as $key) {
            $values = $this->input("audience.{$key}");

            if (is_array($values)) {
                $this->merge(['audience' => [
                    ...$this->input('audience'),
                    $key => array_map(fn ($v) => is_string($v) ? strtoupper(trim($v)) : $v, $values),
                ]]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title' => [$this->req(), 'string', 'max:200'],
            'content' => [$this->req(), 'string'],
            'type' => ['sometimes', Rule::in(Announcement::TYPES)],
            'priority' => ['sometimes', Rule::in(Announcement::PRIORITIES)],

            // A quién va dirigido: todos, uno o más grupos (A/B/C) o carreras.
            'audience' => ['sometimes', 'array'],
            'audience.scope' => ['required_with:audience', Rule::in(Announcement::SCOPES)],
            'audience.groups' => ['required_if:audience.scope,grupo', 'array'],
            'audience.groups.*' => [Rule::in(array_keys(Career::GROUPS))],
            'audience.career_codes' => ['required_if:audience.scope,carrera', 'array'],
            'audience.career_codes.*' => ['string', 'exists:careers,code'],

            'valid_from' => ['nullable', 'date'],
            // Solo se compara si llega valid_from; si no, Laravel tomaría "valid_from" como fecha.
            'valid_until' => ['nullable', 'date', ...($this->filled('valid_from') ? ['after_or_equal:valid_from'] : [])],

            // Enviar adjuntos reemplaza los anteriores.
            'attachments' => ['sometimes', 'array', 'max:'.self::MAX_ATTACHMENTS],
            'attachments.*' => PublicContentFiles::ATTACHMENT_RULES,

            'status' => ['sometimes', Rule::in(Announcement::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'audience.groups.required_if' => 'Indica a qué grupos va dirigido.',
            'audience.career_codes.required_if' => 'Indica a qué carreras va dirigido.',
            'audience.career_codes.*.exists' => 'La carrera :input no existe.',
            'valid_until.after_or_equal' => 'La fecha de fin debe ser igual o posterior a la de inicio.',
            'attachments.*.mimes' => 'Cada adjunto debe ser PDF, JPG o PNG.',
            'attachments.*.max' => 'Cada adjunto puede pesar como máximo 5 MB.',
        ];
    }

    /** Audiencia normalizada: siempre con las tres claves. */
    public function audience(): ?array
    {
        if (! $this->has('audience')) {
            return null;
        }

        $scope = $this->input('audience.scope');

        return [
            'scope' => $scope,
            'groups' => $scope === 'grupo' ? array_values(array_unique($this->input('audience.groups', []))) : [],
            'career_codes' => $scope === 'carrera' ? array_values(array_unique($this->input('audience.career_codes', []))) : [],
        ];
    }
}
