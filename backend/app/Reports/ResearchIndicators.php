<?php

namespace App\Reports;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Payment;
use App\Models\User;
use App\Reports\Support\ReportDocument;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportQueries;
use App\Reports\Support\ReportTable;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;

/**
 * Indicadores de la investigación (tesis), por semestre.
 *
 * D1 Eficiencia temporal (horas):
 *    - Matrícula: de su creación (created_at) a su aprobación (approved_at).
 *    - Pago: de su registro (created_at) a su validación (validated_at).
 *    Solo casos ya resueltos (aprobados / validados).
 * D2 Exactitud operativa:
 *    - % rechazados sobre lo ya decidido: pagos rechazados / (validados + rechazados);
 *      matrículas rechazadas / (aprobadas + rechazadas). Lo pendiente no cuenta.
 *    - Motivos de rechazo más frecuentes (texto exacto, sin mayúsculas/espacios extra).
 *    - Importaciones de exámenes: filas con error y DNI no encontrados sobre filas leídas
 *      (según audit_logs; una importación sin ninguna fila válida no se registra).
 * D4 Trazabilidad:
 *    - % de operaciones con registro en audit_logs, por tipo: matrículas aprobadas o
 *      rechazadas, pagos revisados, importaciones y publicaciones de exámenes.
 *    - Acciones registradas por cada usuario administrativo.
 */
class ResearchIndicators
{
    public const TOP_REASONS = 5;

    public function build(ReportFilters $filters): array
    {
        $semesterId = $filters->semester->id;
        $enrollments = Enrollment::where('semester_id', $semesterId)->get(['id', 'status', 'created_at', 'approved_at', 'rejected_at', 'rejection_reason']);
        $payments = Payment::whereIn('enrollment_id', $enrollments->pluck('id'))->get(['id', 'status', 'created_at', 'validated_at', 'rejection_reason']);
        $exams = Exam::where('semester_id', $semesterId)->get(['id', 'name', 'status', 'results_imported_at']);
        $logs = $this->auditLogsFor($enrollments, $payments, $exams);

        return [
            'semester' => $filters->semester->only(['id', 'code', 'name']),
            'd1_time_efficiency' => [
                'enrollment_approval_hours' => $this->durationStats(
                    $enrollments->where('status', 'approved')->filter(fn ($e) => $e->approved_at),
                    fn ($e) => $e->created_at->diffInSeconds($e->approved_at) / 3600,
                ),
                'payment_validation_hours' => $this->durationStats(
                    $payments->where('status', Payment::STATUS_VALIDATED)->filter(fn ($p) => $p->validated_at),
                    fn ($p) => $p->created_at->diffInSeconds($p->validated_at) / 3600,
                ),
            ],
            'd2_operational_accuracy' => [
                'payments' => $this->rejectionStats($payments, Payment::STATUS_VALIDATED, Payment::STATUS_REJECTED),
                'enrollments' => $this->rejectionStats($enrollments, 'approved', 'rejected'),
                'exam_imports' => $this->importStats($logs),
            ],
            'd4_traceability' => $this->traceability($enrollments, $payments, $exams, $logs),
        ];
    }

    /** Hoja de cálculo: un resumen y una hoja por dimensión. */
    public function toDocument(ReportFilters $filters, array $data): ReportDocument
    {
        $d1 = $data['d1_time_efficiency'];
        $d2 = $data['d2_operational_accuracy'];
        $d4 = $data['d4_traceability'];

        $timeRow = fn (string $label, array $s) => ['indicator' => $label, ...$s];
        $reasonRows = fn (string $type, array $reasons) => array_map(fn ($r) => ['type' => $type, ...$r], $reasons);

        return new ReportDocument(
            title: 'Indicadores de investigación',
            filters: $filters->describe(),
            summary: [
                'D1 · Aprobación de matrícula (h promedio)' => $d1['enrollment_approval_hours']['average'],
                'D1 · Validación de pago (h promedio)' => $d1['payment_validation_hours']['average'],
                'D2 · Pagos rechazados (%)' => $d2['payments']['rejected_rate'],
                'D2 · Matrículas rechazadas (%)' => $d2['enrollments']['rejected_rate'],
                'D2 · Filas con error en importaciones (%)' => $d2['exam_imports']['error_rate'],
                'D4 · Operaciones con registro de auditoría (%)' => $d4['overall']['rate'],
            ],
            tables: [
                new ReportTable('D1 Eficiencia temporal (h)', [
                    'indicator' => 'Indicador', 'count' => 'Casos', 'average' => 'Promedio', 'min' => 'Mínimo', 'max' => 'Máximo', 'average_days' => 'Promedio (días)',
                ], [
                    $timeRow('Creación → aprobación de matrícula', $d1['enrollment_approval_hours']),
                    $timeRow('Registro → validación de pago', $d1['payment_validation_hours']),
                ]),
                new ReportTable('D2 Rechazos', [
                    'type' => 'Tipo', 'decided' => 'Decididos', 'rejected' => 'Rechazados', 'rejected_rate' => 'Rechazados (%)', 'pending' => 'Pendientes',
                ], [
                    ['type' => 'Pagos', ...array_diff_key($d2['payments'], ['top_reasons' => 0])],
                    ['type' => 'Matrículas', ...array_diff_key($d2['enrollments'], ['top_reasons' => 0])],
                ]),
                new ReportTable('D2 Motivos de rechazo', ['type' => 'Tipo', 'reason' => 'Motivo', 'count' => 'Veces'], [
                    ...$reasonRows('Pago', $d2['payments']['top_reasons']),
                    ...$reasonRows('Matrícula', $d2['enrollments']['top_reasons']),
                ]),
                new ReportTable('D2 Importaciones de exámenes', [
                    'imports' => 'Importaciones', 'total_rows' => 'Filas leídas', 'imported' => 'Importadas',
                    'not_found' => 'DNI no encontrados', 'errors' => 'Filas con error', 'error_rate' => 'Error (%)',
                ], [$d2['exam_imports']]),
                new ReportTable('D4 Trazabilidad', [
                    'operation' => 'Operación', 'total' => 'Operaciones', 'audited' => 'Con auditoría', 'rate' => 'Cobertura (%)',
                ], array_values($d4['operations'])),
                new ReportTable('D4 Acciones por usuario', [
                    'name' => 'Usuario', 'role' => 'Rol', 'actions' => 'Acciones', 'detail' => 'Detalle',
                ], array_map(fn ($u) => [...$u, 'detail' => collect($u['by_action'])->map(fn ($n, $a) => "{$a}: {$n}")->implode(', ')], $d4['actions_by_user'])),
            ],
            notes: [
                'D1: horas entre las marcas de tiempo del sistema; solo casos ya aprobados o validados.',
                'D2: % de rechazo sobre lo ya decidido (lo pendiente no cuenta).',
                'D4: una operación está trazada si existe su registro en audit_logs.',
            ],
        );
    }

    /** @return array{count: int, average: ?float, min: ?float, max: ?float, average_days: ?float} */
    private function durationStats(Collection $items, callable $hours): array
    {
        $values = $items->map($hours)->values();

        return [
            'count' => $values->count(),
            'average' => $values->isEmpty() ? null : round($values->avg(), 2),
            'min' => $values->isEmpty() ? null : round($values->min(), 2),
            'max' => $values->isEmpty() ? null : round($values->max(), 2),
            'average_days' => $values->isEmpty() ? null : round($values->avg() / 24, 2),
        ];
    }

    private function rejectionStats(Collection $items, string $accepted, string $rejected): array
    {
        $rejectedItems = $items->where('status', $rejected);
        $decided = $items->whereIn('status', [$accepted, $rejected])->count();

        return [
            'decided' => $decided,
            'rejected' => $rejectedItems->count(),
            'rejected_rate' => ReportQueries::rate($rejectedItems->count(), $decided),
            'pending' => $items->count() - $decided,
            'top_reasons' => $rejectedItems
                ->map(fn ($i) => trim(preg_replace('/\s+/', ' ', (string) $i->rejection_reason)))
                ->filter()
                ->groupBy(fn ($reason) => mb_strtolower($reason))
                ->map(fn ($group) => ['reason' => $group->first(), 'count' => $group->count()])
                ->sortByDesc('count')
                ->take(self::TOP_REASONS)
                ->values()
                ->all(),
        ];
    }

    private function importStats(Collection $logs): array
    {
        $imports = $logs->where('action', AuditLogger::EXAM_RESULTS_IMPORTED);
        $sum = fn (string $key) => (int) $imports->sum(fn ($log) => $log->metadata[$key] ?? 0);
        $totalRows = $sum('total_rows');

        return [
            'imports' => $imports->count(),
            'total_rows' => $totalRows,
            'imported' => $sum('imported'),
            'not_found' => $sum('not_found'),
            'errors' => $sum('errors'),
            'error_rate' => ReportQueries::rate($sum('not_found') + $sum('errors'), $totalRows),
        ];
    }

    private function traceability(Collection $enrollments, Collection $payments, Collection $exams, Collection $logs): array
    {
        $audited = fn (array $actions, string $key) => $logs->whereIn('action', $actions)
            ->map(fn ($log) => $log->metadata[$key] ?? null)->filter()->unique();

        $operation = function (string $label, Collection $ids, Collection $auditedIds) {
            $covered = $ids->intersect($auditedIds)->count();

            return ['operation' => $label, 'total' => $ids->count(), 'audited' => $covered, 'rate' => ReportQueries::rate($covered, $ids->count())];
        };

        $operations = [
            'enrollment_decisions' => $operation(
                'Matrículas aprobadas o rechazadas',
                $enrollments->whereIn('status', ['approved', 'rejected'])->pluck('id'),
                $audited([AuditLogger::ENROLLMENT_APPROVED, AuditLogger::ENROLLMENT_REJECTED], 'enrollment_id'),
            ),
            'payment_reviews' => $operation(
                'Pagos validados o rechazados',
                $payments->whereIn('status', [Payment::STATUS_VALIDATED, Payment::STATUS_REJECTED])->pluck('id'),
                $audited([AuditLogger::PAYMENT_VALIDATED, AuditLogger::PAYMENT_REJECTED], 'payment_id'),
            ),
            'exam_imports' => $operation(
                'Exámenes con resultados importados',
                $exams->whereNotNull('results_imported_at')->pluck('id'),
                $audited([AuditLogger::EXAM_RESULTS_IMPORTED], 'exam_id'),
            ),
            'exam_publications' => $operation(
                'Exámenes publicados',
                $exams->where('status', Exam::STATUS_PUBLISHED)->pluck('id'),
                $audited([AuditLogger::EXAM_PUBLISHED], 'exam_id'),
            ),
        ];

        $total = array_sum(array_column($operations, 'total'));
        $covered = array_sum(array_column($operations, 'audited'));
        $users = User::whereIn('id', $logs->pluck('admin_id')->filter()->unique())->get(['id', 'name', 'role'])->keyBy('id');

        return [
            'operations' => $operations,
            'overall' => ['total' => $total, 'audited' => $covered, 'rate' => ReportQueries::rate($covered, $total)],
            'actions_by_user' => $logs->groupBy('admin_id')
                ->map(fn ($userLogs, $adminId) => [
                    'user_id' => (int) $adminId,
                    'name' => $users->get($adminId)?->name,
                    'role' => $users->get($adminId)?->role,
                    'actions' => $userLogs->count(),
                    'by_action' => $userLogs->countBy('action')->sortKeys()->all(),
                ])
                ->sortByDesc('actions')
                ->values()
                ->all(),
        ];
    }

    /** Registros de auditoría de las matrículas, pagos y exámenes del semestre. */
    private function auditLogsFor(Collection $enrollments, Collection $payments, Collection $exams): Collection
    {
        $ids = [
            'enrollment_id' => $enrollments->pluck('id')->flip(),
            'payment_id' => $payments->pluck('id')->flip(),
            'exam_id' => $exams->pluck('id')->flip(),
        ];

        $actions = [
            AuditLogger::ENROLLMENT_APPROVED => 'enrollment_id',
            AuditLogger::ENROLLMENT_REJECTED => 'enrollment_id',
            AuditLogger::ENROLLMENT_REOPENED => 'enrollment_id',
            AuditLogger::PAYMENT_VALIDATED => 'payment_id',
            AuditLogger::PAYMENT_REJECTED => 'payment_id',
            AuditLogger::EXAM_RESULTS_IMPORTED => 'exam_id',
            AuditLogger::EXAM_PUBLISHED => 'exam_id',
        ];

        return AuditLog::whereIn('action', array_keys($actions))->get()
            ->filter(function (AuditLog $log) use ($actions, $ids) {
                $key = $actions[$log->action];

                return isset($log->metadata[$key]) && $ids[$key]->has($log->metadata[$key]);
            })
            ->values();
    }
}
