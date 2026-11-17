<?php

namespace App\Reports\Support;

use App\Models\Career;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Semester;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Filtros comunes de dashboard, reportes e indicadores: semestre (por defecto
 * el activo), carrera, grupo y aula.
 */
final class ReportFilters
{
    public function __construct(
        public readonly Semester $semester,
        public readonly ?Career $career = null,
        public readonly ?string $group = null,
        public readonly ?Classroom $classroom = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $data = $request->validate([
            'semester_id' => ['sometimes', 'integer', 'exists:semesters,id'],
            'career_id' => ['sometimes', 'integer', 'exists:careers,id'],
            'group' => ['sometimes', Rule::in(array_keys(Career::GROUPS))],
            'classroom_id' => ['sometimes', 'integer', 'exists:classrooms,id'],
        ]);

        $semester = isset($data['semester_id']) ? Semester::find($data['semester_id']) : Semester::current();

        if (! $semester) {
            throw ValidationException::withMessages([
                'semester_id' => 'No hay un semestre activo: indica semester_id.',
            ]);
        }

        return new self(
            $semester,
            isset($data['career_id']) ? Career::find($data['career_id']) : null,
            $data['group'] ?? null,
            isset($data['classroom_id']) ? Classroom::find($data['classroom_id']) : null,
        );
    }

    /** Matrículas del semestre con los filtros de carrera, grupo y aula. */
    public function enrollments(): Builder
    {
        return Enrollment::query()
            ->where('enrollments.semester_id', $this->semester->id)
            ->when($this->career, fn ($q) => $q->where('enrollments.career_id', $this->career->id))
            ->when($this->group, fn ($q) => $q->whereHas('career', fn ($c) => $c->where('group', $this->group)))
            ->when($this->classroom, fn ($q) => $q->where('enrollments.classroom_id', $this->classroom->id));
    }

    /** Descripción legible para el encabezado de PDF y Excel. */
    public function describe(): array
    {
        return array_filter([
            'Semestre' => $this->semester->name ?? $this->semester->code,
            'Carrera' => $this->career?->name,
            'Grupo' => $this->group ? "{$this->group} — ".Career::GROUPS[$this->group] : null,
            'Aula' => $this->classroom ? ($this->classroom->name ?? $this->classroom->number) : null,
        ]);
    }

    public function toArray(): array
    {
        return [
            'semester' => $this->semester->only(['id', 'code', 'name']),
            'career' => $this->career?->only(['id', 'code', 'name']),
            'group' => $this->group,
            'classroom' => $this->classroom?->only(['id', 'number', 'name']),
        ];
    }
}
