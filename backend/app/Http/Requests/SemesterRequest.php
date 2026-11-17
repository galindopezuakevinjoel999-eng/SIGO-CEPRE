<?php

namespace App\Http\Requests;

use App\Models\Semester;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear (POST) o editar (PUT/PATCH) un ciclo. El código, el número de ciclo y
 * el nombre se derivan del tipo y el año (Semester::codeFor / nameFor).
 *
 * payment_plans: modalidades de pago del ciclo, con cuotas que pueden ser
 * desiguales, p. ej. {"unico": {"label": "Pago único", "installments": [600]},
 * "fraccionado": {"label": "Pago fraccionado", "installments": [500, 150]}}.
 */
class SemesterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $req = $this->isCreating() ? 'required' : 'sometimes';

        return [
            'type' => [$req, Rule::in(array_keys(Semester::TYPES))],
            'year' => [$req, 'integer', 'between:2020,2100'],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'enrollment_start' => ['sometimes', 'nullable', 'date'],
            'enrollment_end' => ['sometimes', 'nullable', 'date', ...$this->afterOrEqual('enrollment_start')],
            'classes_start' => ['sometimes', 'nullable', 'date'],
            'classes_end' => ['sometimes', 'nullable', 'date', ...$this->afterOrEqual('classes_start')],
            'min_admission_score' => ['sometimes', 'nullable', 'numeric'],
            'payment_plans' => ['sometimes', 'nullable', 'array', 'max:5'],
            'payment_plans.*' => ['array'],
            'payment_plans.*.label' => ['required', 'string', 'max:60'],
            'payment_plans.*.installments' => ['required', 'array', 'min:1', 'max:6'],
            'payment_plans.*.installments.*' => ['numeric', 'gt:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'El tipo de ciclo debe ser intensivo, ordinario_i u ordinario_ii.',
            'enrollment_end.after_or_equal' => 'El fin de la inscripción no puede ser anterior a su inicio.',
            'classes_end.after_or_equal' => 'El fin de clases no puede ser anterior a su inicio.',
            'payment_plans.*.installments.*.gt' => 'Cada cuota debe ser mayor que cero.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (array_keys($this->input('payment_plans') ?? []) as $key) {
                    if (! preg_match('/^[a-z_]{2,30}$/', (string) $key)) {
                        $validator->errors()->add('payment_plans', "La modalidad \"{$key}\" debe ser un identificador en minúsculas (ej. unico, fraccionado).");
                    }
                }

                [$type, $year] = $this->typeAndYear();
                $code = Semester::codeFor($type, $year);
                $taken = Semester::where('code', $code)->when($this->route('semester'), fn ($q, $s) => $q->whereKeyNot($s->id))->exists();

                if ($taken) {
                    $validator->errors()->add('type', 'Ya existe el '.Semester::nameFor($type, $year)." (código {$code}).");
                }
            },
        ];
    }

    /** Datos listos para guardar: con código, número de ciclo y nombre derivados. */
    public function semesterData(): array
    {
        $data = $this->safe()->except('is_active');
        [$type, $year] = $this->typeAndYear();

        if ($this->isCreating() || $this->hasAny(['type', 'year'])) {
            $data['code'] = Semester::codeFor($type, $year);
            $data['cycle'] = Semester::TYPES[$type]['cycle'];
            $data['name'] = $this->filled('name') ? $this->input('name') : Semester::nameFor($type, $year);
        }

        return $data;
    }

    /** @return array{0: string, 1: int} */
    private function typeAndYear(): array
    {
        $semester = $this->route('semester');

        return [
            $this->input('type', $semester?->type),
            (int) $this->input('year', $semester?->year),
        ];
    }

    private function afterOrEqual(string $field): array
    {
        return $this->filled($field) ? ["after_or_equal:{$field}"] : [];
    }

    private function isCreating(): bool
    {
        return $this->isMethod('post');
    }
}
