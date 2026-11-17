<?php

namespace App\Reports\Support;

use App\Models\Attendance;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cálculos que comparten el dashboard y los reportes, para que un mismo número
 * se calcule siempre de la misma forma.
 *
 * Definiciones:
 *  - Ingreso: suma de pagos validados.
 *  - Saldo de una matrícula: costo de matrícula − pagos validados.
 *  - Deudor: matrícula pendiente o aprobada (no rechazada) con saldo > 0.
 *  - Estado de pago: "pagado" (saldo 0), "parcial" (pagó algo, saldo > 0),
 *    "sin_pago" (ningún pago validado).
 *  - Tasa de asistencia: (presentes + tardanzas) / registros × 100.
 *  - En riesgo / inhabilitado: faltas del semestre ≥ absence_warning_1 /
 *    max_absences (la misma regla de User::refreshAttendanceStatus()).
 */
class ReportQueries
{
    public function __construct(private PaymentService $payments) {}

    public function enrollmentCost(): ?float
    {
        return $this->payments->enrollmentCost();
    }

    /** Pagos de las matrículas que cumplen los filtros. */
    public function payments(ReportFilters $filters): Builder
    {
        return Payment::query()->whereIn('enrollment_id', $filters->enrollments()->select('enrollments.id'));
    }

    /**
     * Situación de pago de cada matrícula no rechazada.
     *
     * @return Collection<int, array{enrollment: Enrollment, validated: float, pending: float, balance: ?float, status: string}>
     */
    public function paymentStatusByEnrollment(ReportFilters $filters): Collection
    {
        $cost = $this->enrollmentCost();

        $enrollments = $filters->enrollments()
            ->where('status', '!=', 'rejected')
            ->with(['student:id,name,dni', 'career:id,code,name,group', 'classroom:id,number,name'])
            ->withSum(['payments as validated_total' => fn ($q) => $q->where('status', Payment::STATUS_VALIDATED)], 'amount')
            ->withSum(['payments as pending_total' => fn ($q) => $q->where('status', Payment::STATUS_PENDING)], 'amount')
            ->orderBy('id')
            ->get();

        return $enrollments->map(function (Enrollment $enrollment) use ($cost) {
            $validated = round((float) $enrollment->validated_total, 2);
            $balance = $cost === null ? null : max(0, round($cost - $validated, 2));

            return [
                'enrollment' => $enrollment,
                'validated' => $validated,
                'pending' => round((float) $enrollment->pending_total, 2),
                'balance' => $balance,
                'status' => match (true) {
                    $validated <= 0 => 'sin_pago',
                    $balance !== null && $balance <= 0 => 'pagado',
                    default => 'parcial',
                },
            ];
        });
    }

    /**
     * Asistencia por estudiante en los horarios del semestre.
     *
     * @return Collection<int, array{student_id: int, total: int, present: int, late: int, absent: int, rate: ?float, risk: string}>
     */
    public function attendanceByStudent(ReportFilters $filters): Collection
    {
        [$warning, $max] = $this->absenceThresholds();

        return $this->attendances($filters)
            ->selectRaw('student_id, count(*) as total')
            ->selectRaw("sum(case when status = 'present' then 1 else 0 end) as present")
            ->selectRaw("sum(case when status = 'late' then 1 else 0 end) as late")
            ->selectRaw("sum(case when status = 'absent' then 1 else 0 end) as absent")
            ->groupBy('student_id')
            ->get()
            ->map(fn ($row) => [
                'student_id' => (int) $row->student_id,
                'total' => (int) $row->total,
                'present' => (int) $row->present,
                'late' => (int) $row->late,
                'absent' => (int) $row->absent,
                'rate' => self::rate((int) $row->present + (int) $row->late, (int) $row->total),
                'risk' => match (true) {
                    (int) $row->absent >= $max => 'inhabilitado',
                    (int) $row->absent >= $warning => 'en_riesgo',
                    default => 'activo',
                },
            ])
            ->keyBy('student_id');
    }

    /**
     * Asistencias del semestre (por el horario), de estudiantes cuya matrícula
     * cumple los filtros. El aula es la del horario.
     */
    public function attendances(ReportFilters $filters): Builder
    {
        return Attendance::query()
            ->whereHas('schedule', fn ($q) => $q->where('semester_id', $filters->semester->id)
                ->when($filters->classroom, fn ($s) => $s->where('classroom_id', $filters->classroom->id)))
            ->when($filters->career || $filters->group, fn ($q) => $q->whereIn(
                'student_id',
                $filters->enrollments()->where('status', 'approved')->select('student_id'),
            ));
    }

    /** @return array{0: int, 1: int} [en riesgo desde, inhabilitado desde] */
    public function absenceThresholds(): array
    {
        return [
            (int) SystemSetting::getValue('absence_warning_1', User::DEFAULT_ABSENCE_WARNING),
            (int) SystemSetting::getValue('max_absences', User::DEFAULT_MAX_ABSENCES),
        ];
    }

    /** Porcentaje con un decimal; null si no hay base. */
    public static function rate(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    public static function money(?float $amount): ?string
    {
        return $amount === null ? null : 'S/ '.number_format($amount, 2, '.', ',');
    }

    public static function semesterLabel(Semester $semester): string
    {
        return $semester->name ?? $semester->code;
    }
}
