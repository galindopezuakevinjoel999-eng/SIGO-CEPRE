<?php

namespace App\Reports;

use App\Models\User;
use App\Reports\Support\ReportDocument;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportQueries;
use App\Reports\Support\ReportTable;

/** Reporte de asistencia (RF37): % por estudiante y por aula; estudiantes en riesgo. */
class AttendanceReport
{
    public const RISK_LABELS = ['activo' => 'Activo', 'en_riesgo' => 'En riesgo', 'inhabilitado' => 'Inhabilitado'];

    public function __construct(private ReportQueries $queries) {}

    public function build(ReportFilters $filters): ReportDocument
    {
        $byStudent = $this->queries->attendanceByStudent($filters);
        [$warning, $max] = $this->queries->absenceThresholds();

        $students = User::whereIn('id', $byStudent->keys())->get(['id', 'name', 'dni'])->keyBy('id');
        $enrollments = $filters->enrollments()->where('status', 'approved')
            ->whereIn('student_id', $byStudent->keys())
            ->with(['career:id,name', 'classroom:id,number'])
            ->get()
            ->keyBy('student_id');

        $studentRows = $byStudent
            ->map(fn ($s) => [
                'student' => $students->get($s['student_id'])?->name,
                'dni' => $students->get($s['student_id'])?->dni,
                'career' => $enrollments->get($s['student_id'])?->career?->name,
                'classroom' => $enrollments->get($s['student_id'])?->classroom?->number,
                'total' => $s['total'],
                'present' => $s['present'],
                'late' => $s['late'],
                'absent' => $s['absent'],
                'rate' => $s['rate'],
                'risk' => self::RISK_LABELS[$s['risk']],
            ])
            ->sortBy(fn ($r) => [$r['rate'], $r['student']])
            ->values();

        // Por aula: la del horario en que se tomó la asistencia.
        $byClassroom = $this->queries->attendances($filters)
            ->join('schedules', 'schedules.id', '=', 'attendances.schedule_id')
            ->leftJoin('classrooms', 'classrooms.id', '=', 'schedules.classroom_id')
            ->select('classrooms.number as classroom')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when attendances.status in ('present', 'late') then 1 else 0 end) as attended")
            ->selectRaw("sum(case when attendances.status = 'absent' then 1 else 0 end) as absent")
            ->groupBy('classrooms.number')
            ->orderBy('classrooms.number')
            ->get()
            ->map(fn ($row) => [
                'classroom' => $row->classroom ?? 'Sin aula',
                'total' => (int) $row->total,
                'attended' => (int) $row->attended,
                'absent' => (int) $row->absent,
                'rate' => ReportQueries::rate((int) $row->attended, (int) $row->total),
            ])
            ->all();

        $records = $byStudent->sum('total');
        $attended = $byStudent->sum(fn ($s) => $s['present'] + $s['late']);
        $studentColumns = [
            'student' => 'Estudiante', 'dni' => 'DNI', 'career' => 'Carrera', 'classroom' => 'Aula',
            'total' => 'Registros', 'present' => 'Presente', 'late' => 'Tardanza', 'absent' => 'Faltas',
            'rate' => 'Asistencia (%)', 'risk' => 'Estado',
        ];

        return new ReportDocument(
            title: 'Reporte de asistencia',
            filters: $filters->describe(),
            summary: [
                'Registros de asistencia' => $records,
                'Asistencia general (%)' => ReportQueries::rate($attended, $records),
                'Estudiantes con registros' => $byStudent->count(),
                "En riesgo (desde {$warning} faltas)" => $byStudent->where('risk', 'en_riesgo')->count(),
                "Inhabilitados (desde {$max} faltas)" => $byStudent->where('risk', 'inhabilitado')->count(),
            ],
            tables: [
                new ReportTable('Estudiantes en riesgo e inhabilitados', $studentColumns, $studentRows->where('risk', '!=', 'Activo')->values()->all()),
                new ReportTable('Asistencia por aula', [
                    'classroom' => 'Aula', 'total' => 'Registros', 'attended' => 'Asistencias', 'absent' => 'Faltas', 'rate' => 'Asistencia (%)',
                ], $byClassroom),
                new ReportTable('Asistencia por estudiante', $studentColumns, $studentRows->all()),
            ],
            notes: ['Asistencia (%) = (presente + tardanza) / registros. Solo cuentan los horarios del semestre.'],
        );
    }
}
