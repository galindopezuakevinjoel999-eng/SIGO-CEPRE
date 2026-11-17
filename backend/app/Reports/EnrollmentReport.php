<?php

namespace App\Reports;

use App\Models\Career;
use App\Models\Enrollment;
use App\Reports\Support\ReportDocument;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportTable;

/** Reporte de matrícula (RF38): matriculados por carrera, grupo y aula, con su código. */
class EnrollmentReport
{
    public function build(ReportFilters $filters): ReportDocument
    {
        $counts = $filters->enrollments()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $approved = $filters->enrollments()
            ->where('status', 'approved')
            ->with(['student:id,name,dni', 'career:id,code,name,group', 'classroom:id,number,name'])
            ->get()
            ->sortBy(fn (Enrollment $e) => [$e->career?->name, $e->classroom?->number, $e->enrollment_code, $e->student?->name])
            ->values();

        $rows = $approved->map(fn (Enrollment $e) => [
            'code' => $e->enrollment_code,
            'student' => $e->student?->name,
            'dni' => $e->student?->dni,
            'career' => $e->career?->name,
            'group' => $e->career?->group,
            'classroom' => $e->classroom?->number,
            'approved_at' => $e->approved_at?->setTimezone(config('app.display_timezone'))->format('d/m/Y'),
        ])->all();

        $byCareer = $approved->groupBy(fn ($e) => $e->career?->name ?? 'Sin carrera')
            ->map(fn ($group, $career) => ['career' => $career, 'group' => $group->first()->career?->group, 'total' => $group->count()])
            ->sortByDesc('total')->values()->all();

        $byClassroom = $approved->groupBy(fn ($e) => $e->classroom?->number ?? 'Sin aula')
            ->map(fn ($group, $classroom) => ['classroom' => $classroom, 'total' => $group->count()])
            ->sortBy('classroom')->values()->all();

        $summary = [
            'Solicitudes de matrícula' => (int) $counts->sum(),
            'Matriculados (aprobados)' => (int) ($counts['approved'] ?? 0),
            'Pendientes' => (int) ($counts['pending'] ?? 0),
            'Rechazados' => (int) ($counts['rejected'] ?? 0),
        ];
        foreach (Career::GROUPS as $group => $label) {
            $summary["Grupo {$group} ({$label})"] = $approved->where('career.group', $group)->count();
        }

        return new ReportDocument(
            title: 'Reporte de matrícula',
            filters: $filters->describe(),
            summary: $summary,
            tables: [
                new ReportTable('Matriculados', [
                    'code' => 'Código', 'student' => 'Estudiante', 'dni' => 'DNI', 'career' => 'Carrera',
                    'group' => 'Grupo', 'classroom' => 'Aula', 'approved_at' => 'Aprobada',
                ], $rows),
                new ReportTable('Matriculados por carrera', ['career' => 'Carrera', 'group' => 'Grupo', 'total' => 'Matriculados'], $byCareer),
                new ReportTable('Matriculados por aula', ['classroom' => 'Aula', 'total' => 'Matriculados'], $byClassroom),
            ],
        );
    }
}
