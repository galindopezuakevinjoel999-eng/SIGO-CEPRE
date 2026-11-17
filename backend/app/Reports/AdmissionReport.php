<?php

namespace App\Reports;

use App\Models\Exam;
use App\Models\ExamResult;
use App\Reports\Support\ReportDocument;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportTable;
use App\Services\Exams\AdmissionService;

/** Reporte de ingresantes de un examen: por carrera, según vacantes y puntaje mínimo del ciclo. */
class AdmissionReport
{
    public function __construct(private AdmissionService $admission) {}

    public function build(ReportFilters $filters, Exam $exam): ReportDocument
    {
        $byCareer = collect($this->admission->forExam($exam))
            ->when($filters->career, fn ($c) => $c->where('career.id', $filters->career->id))
            ->when($filters->group, fn ($c) => $c->where('career.group', $filters->group))
            ->values();

        $minScore = $exam->semester?->min_admission_score;
        $row = fn (ExamResult $r, array $career) => [
            'career' => $career['career']['name'],
            'rank_in_career' => $r->rank_in_career,
            'student' => $r->student?->name,
            'dni' => $r->student?->dni,
            'score' => $r->score,
            'rank' => $r->rank,
        ];

        $admittedRows = $byCareer->flatMap(fn ($c) => array_map(fn ($r) => $row($r, $c), $c['admitted']))->all();
        $notes = ['Ingresan los mejores puestos de cada carrera hasta cubrir sus vacantes, '
            .($minScore === null ? 'sin puntaje mínimo configurado.' : 'con puntaje mayor o igual a '.number_format($minScore, 3).'.')];
        if ($byCareer->contains('tie_at_cutoff', true)) {
            $notes[] = 'Hay empate en la última vacante de al menos una carrera: ingresan todos los empatados (ver "Ingresantes" > "Vacantes").';
        }

        return new ReportDocument(
            title: "Ingresantes: {$exam->name}",
            filters: [...$filters->describe(), 'Examen' => "{$exam->name} ({$exam->date->format('d/m/Y')})"],
            summary: [
                'Vacantes' => $byCareer->sum('vacancies'),
                'Ingresantes' => $byCareer->sum('filled'),
                'Puntaje mínimo' => $minScore,
            ],
            tables: [
                new ReportTable('Vacantes e ingresantes por carrera', [
                    'career' => 'Carrera', 'group' => 'Grupo', 'vacancies' => 'Vacantes', 'filled' => 'Ingresantes', 'tie' => 'Empate en la última vacante',
                ], $byCareer->map(fn ($c) => [
                    'career' => $c['career']['name'],
                    'group' => $c['career']['group'],
                    'vacancies' => $c['vacancies'],
                    'filled' => $c['filled'],
                    'tie' => $c['tie_at_cutoff'] ? 'Sí' : 'No',
                ])->all()),
                new ReportTable('Ingresantes', [
                    'career' => 'Carrera', 'rank_in_career' => 'Puesto en la carrera', 'student' => 'Estudiante',
                    'dni' => 'DNI', 'score' => 'Puntaje', 'rank' => 'Puesto general',
                ], $admittedRows),
            ],
            notes: $notes,
        );
    }
}
