<?php

namespace App\Http\Requests\Exam;

use App\Models\Career;
use App\Models\Exam;
use App\Models\Semester;
use App\Models\SystemSetting;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear (POST) o editar (PUT/PATCH) un examen.
 *
 * Los puntos son el valor que se suma por respuesta (una penalización va en
 * negativo). Si no se envían al crear, se usan los de system_settings
 * (exam_points_correct, exam_points_wrong, exam_points_blank); si tampoco
 * están configurados, son obligatorios.
 */
class ExamRequest extends FormRequest
{
    /** Claves de system_settings con los puntos por defecto. */
    public const DEFAULT_POINTS = [
        'points_correct' => 'exam_points_correct',
        'points_wrong' => 'exam_points_wrong',
        'points_blank' => 'exam_points_blank',
    ];

    public function authorize(): Response
    {
        return $this->isCreating()
            ? Gate::inspect('create', Exam::class)
            : Gate::inspect('update', $this->route('exam'));
    }

    public function rules(): array
    {
        $req = $this->isCreating() ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:150'],
            'type' => [$req, Rule::in(Exam::TYPES)],
            'date' => [$req, 'date'],
            'semester_id' => ['sometimes', 'nullable', 'exists:semesters,id'],
            'group' => ['sometimes', 'nullable', Rule::in(array_keys(Career::GROUPS))],
            'total_questions' => ['sometimes', 'nullable', 'integer', 'between:1,500'],
            'points_correct' => ['sometimes', 'numeric', 'between:-100,100'],
            'points_wrong' => ['sometimes', 'numeric', 'between:-100,100'],
            'points_blank' => ['sometimes', 'numeric', 'between:-100,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'group.in' => 'El grupo debe ser A, B o C (o vacío para todos).',
        ];
    }

    /** Al crear, cada puntaje debe venir en la petición o tener un valor por defecto. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->isCreating()) {
                    return;
                }

                foreach (self::DEFAULT_POINTS as $field => $setting) {
                    if (! $this->has($field) && ! is_numeric(SystemSetting::getValue($setting))) {
                        $validator->errors()->add($field, "Indica el puntaje ({$field}): no hay un valor por defecto configurado ({$setting}).");
                    }
                }
            },
        ];
    }

    /** Datos listos para guardar: con puntos por defecto y semestre activo si faltan. */
    public function examData(): array
    {
        $data = $this->validated();

        if ($this->isCreating()) {
            foreach (self::DEFAULT_POINTS as $field => $setting) {
                $data[$field] ??= (float) SystemSetting::getValue($setting);
            }

            if (! array_key_exists('semester_id', $data)) {
                $data['semester_id'] = Semester::current()?->id;
            }
        }

        return $data;
    }

    private function isCreating(): bool
    {
        return $this->isMethod('post');
    }
}
