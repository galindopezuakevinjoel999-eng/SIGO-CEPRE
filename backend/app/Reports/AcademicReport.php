<?php

namespace App\Reports;

use App\Models\Exam;
use App\Models\ExamResult;
use App\Reports\Support\ReportDocument;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportTable;
use Illuminate\Support\Collection;

/**
 * Reporte académico (RF40): resultados por examen con ranking general y por
 * grupo, y promedios por aula y por carrera. Solo exámenes publicados.
 * El aula y la carrera son las de la matrícula aprobada del estudiante en el semestre.
 */
class AcademicReport
{
    public function build(ReportFilters $filters, ?int $examId = null): ReportDocument
    {
        $exams = Exam::published()
            ->where('semester_id', $filters->semester->id)
            ->when($examId, fn ($q) => $q->whereKey($examId))
            ->orderBy('date')
            ->get();

        // Carrera y aula de cada estudiante en el semestre.
        $enrollments = $filters->enrollments()
            ->where('status', 'approved')
            ->with(['career:id,code,name,group', 'classroom:id,number,name'])
            ->get()
            ->keyBy('student_id');

        $filtered = $filters->career || $filters->group || $filters->classroom;
        $tables = [];
        $byClassroom = [];
        $byCareer = [];
        $participants = 0;

        foreach ($exams as $exam) {
            $results = $exam->results()->with('student:id,name,dni')->orderBy('rank')->orderBy('id')->get()
                ->when($filtered, fn (Collection $c) => $c->filter(fn (ExamResult $r) => $enrollments->has($r->student_id)))
                ->values();

            $participants += $results->count();

            $tables[] = new ReportTable("Resultados: {$exam->name} ({$exam->date->format('d/m/Y')})", [
                'rank' => 'Puesto', 'rank_in_group' => 'Puesto en grupo', 'student' => 'Estudiante', 'dni' => 'DNI',
                'career' => 'Carrera', 'group' => 'Grupo', 'classroom' => 'Aula',
                'correct' => 'Correctas', 'wrong' => 'Incorrectas', 'blank' => 'En blanco', 'score' => 'Puntaje',
            ], $results->map(fn (ExamResult $r) => [
                'rank' => $r->rank,
                'rank_in_group' => $r->rank_in_group,
                'student' => $r->student?->name,
                'dni' => $r->student?->dni,
                'career' => $enrollments->get($r->student_id)?->career?->name,
                'group' => $r->group,
                'classroom' => $enrollments->get($r->student_id)?->classroom?->number,
                'correct' => $r->correct,
                'wrong' => $r->wrong,
                'blank' => $r->blank,
                'score' => $r->score,
            ])->all());

            foreach ($this->averages($results, fn ($r) => $enrollments->get($r->student_id)?->classroom?->number ?? 'Sin aula') as $key => $avg) {
                $byClassroom[] = ['exam' => $exam->name, 'classroom' => $key, ...$avg];
            }
            foreach ($this->averages($results, fn ($r) => $enrollments->get($r->student_id)?->career?->name ?? 'Sin carrera') as $key => $avg) {
                $byCareer[] = ['exam' => $exam->name, 'career' => $key, ...$avg];
            }
        }

        $averageColumns = ['participants' => 'Participantes', 'average' => 'Promedio', 'max' => 'Máximo', 'min' => 'Mínimo'];

        return new ReportDocument(
            title: 'Reporte académico',
            filters: $filters->describe(),
            summary: [
                'Exámenes publicados' => $exams->count(),
                'Resultados incluidos' => $participants,
            ],
            tables: [
                new ReportTable('Promedios por aula', ['exam' => 'Examen', 'classroom' => 'Aula', ...$averageColumns], $byClassroom),
                new ReportTable('Promedios por carrera', ['exam' => 'Examen', 'career' => 'Carrera', ...$averageColumns], $byCareer),
                ...$tables,
            ],
            notes: ['Ranking con empates: comparten puesto y el siguiente salta (1, 1, 3).'],
        );
    }

    /** @return array<string, array{participants: int, average: float, max: float, min: float}> */
    private function averages(Collection $results, callable $key): array
    {
        return $results->groupBy($key)
            ->sortKeys()
            ->map(fn (Collection $group) => [
                'participants' => $group->count(),
                'average' => round($group->avg('score'), 3),
                'max' => $group->max('score'),
                'min' => $group->min('score'),
            ])
            ->all();
    }
}
