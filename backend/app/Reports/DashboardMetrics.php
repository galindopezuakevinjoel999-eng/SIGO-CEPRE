<?php

namespace App\Reports;

use App\Models\Career;
use App\Models\Exam;
use App\Models\Payment;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportQueries;

/**
 * Indicadores del dashboard del administrador (RF38–RF40) para un semestre.
 * Definiciones en ReportQueries.
 */
class DashboardMetrics
{
    public function __construct(private ReportQueries $queries) {}

    public function build(ReportFilters $filters): array
    {
        return [
            'filters' => $filters->toArray(),
            'enrollments' => $this->enrollments($filters),
            'payments' => $this->payments($filters),
            'last_exam' => $this->lastExam($filters),
            'attendance' => $this->attendance($filters),
            'enrollments_by_career' => $this->byCareer($filters),
            'enrollments_by_group' => $this->byGroup($filters),
        ];
    }

    private function enrollments(ReportFilters $filters): array
    {
        $counts = $filters->enrollments()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'pending' => (int) ($counts['pending'] ?? 0),
            'approved' => (int) ($counts['approved'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
        ];
    }

    private function payments(ReportFilters $filters): array
    {
        $byStatus = $this->queries->payments($filters)
            ->selectRaw('status, count(*) as total, sum(amount) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $status = fn (string $s) => [
            'count' => (int) ($byStatus[$s]->total ?? 0),
            'amount' => round((float) ($byStatus[$s]->amount ?? 0), 2),
        ];

        $cost = $this->queries->enrollmentCost();
        $debtors = $cost === null ? null : $this->queries->paymentStatusByEnrollment($filters)->where('balance', '>', 0);

        return [
            'validated' => $status(Payment::STATUS_VALIDATED),
            'pending' => $status(Payment::STATUS_PENDING),
            'rejected' => $status(Payment::STATUS_REJECTED),
            'income' => $status(Payment::STATUS_VALIDATED)['amount'],
            'enrollment_cost' => $cost,
            // Sin costo configurado no se puede saber quién debe.
            'debtors' => $debtors === null ? null : [
                'count' => $debtors->count(),
                'total_debt' => round($debtors->sum('balance'), 2),
            ],
        ];
    }

    /** Promedio del último examen publicado del semestre (por fecha). */
    private function lastExam(ReportFilters $filters): ?array
    {
        $exam = Exam::published()
            ->where('semester_id', $filters->semester->id)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if (! $exam) {
            return null;
        }

        $results = $exam->results()
            ->when($filters->career || $filters->group || $filters->classroom, fn ($q) => $q->whereIn(
                'student_id',
                $filters->enrollments()->where('status', 'approved')->select('student_id'),
            ));

        return [
            'exam' => $exam->only(['id', 'name', 'type', 'date']),
            'participants' => (clone $results)->count(),
            'average_score' => ($avg = (clone $results)->avg('score')) === null ? null : round((float) $avg, 3),
            'max_score' => ($max = (clone $results)->max('score')) === null ? null : (float) $max,
            'min_score' => ($min = (clone $results)->min('score')) === null ? null : (float) $min,
        ];
    }

    private function attendance(ReportFilters $filters): array
    {
        $byStudent = $this->queries->attendanceByStudent($filters);
        $records = $byStudent->sum('total');
        $attended = $byStudent->sum(fn ($s) => $s['present'] + $s['late']);
        [$warning, $max] = $this->queries->absenceThresholds();

        return [
            'records' => $records,
            'attended' => $attended,
            'absent' => $byStudent->sum('absent'),
            'rate' => ReportQueries::rate($attended, $records),
            'students_at_risk' => $byStudent->where('risk', 'en_riesgo')->count(),
            'students_disabled' => $byStudent->where('risk', 'inhabilitado')->count(),
            'thresholds' => ['at_risk_from' => $warning, 'disabled_from' => $max],
        ];
    }

    private function byCareer(ReportFilters $filters): array
    {
        $counts = $filters->enrollments()
            ->selectRaw('enrollments.career_id, enrollments.status, count(*) as total')
            ->groupBy('enrollments.career_id', 'enrollments.status')
            ->get()
            ->groupBy('career_id');

        $careers = Career::whereIn('id', $counts->keys()->filter())->get()->keyBy('id');

        return $counts->map(fn ($rows, $careerId) => [
            'career' => $careers->get($careerId)?->only(['id', 'code', 'name', 'group']),
            'total' => (int) $rows->sum('total'),
            'approved' => (int) $rows->where('status', 'approved')->sum('total'),
        ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    private function byGroup(ReportFilters $filters): array
    {
        $counts = $filters->enrollments()
            ->join('careers', 'careers.id', '=', 'enrollments.career_id')
            ->select('careers.group as career_group', 'enrollments.status')
            ->selectRaw('count(*) as total')
            ->groupBy('careers.group', 'enrollments.status')
            ->get()
            ->groupBy('career_group');

        return collect(Career::GROUPS)->map(fn (string $label, string $group) => [
            'group' => $group,
            'label' => $label,
            'total' => (int) ($counts[$group] ?? collect())->sum('total'),
            'approved' => (int) ($counts[$group] ?? collect())->where('status', 'approved')->sum('total'),
        ])->values()->all();
    }
}
