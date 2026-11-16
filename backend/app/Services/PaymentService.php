<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de los pagos (RF17–RF22).
 *
 * El estudiante solo envía monto, método, comprobante, matrícula y número de
 * cuota. Lo demás lo calcula el sistema:
 *  - balance:          costo de matrícula (system_settings) − suma de pagos validados.
 *  - installment_plan: el costo dividido en las cuotas configuradas.
 *  - partial:          si el monto no cubre todo lo que se debe.
 *  - paid_date:        la fecha en que se registra el pago.
 */
class PaymentService
{
    /** Clave en system_settings con el costo total de la matrícula (decimal). */
    public const COST_SETTING = 'enrollment_cost';

    /** Clave en system_settings con el número de cuotas permitidas (entero, por defecto 1). */
    public const INSTALLMENTS_SETTING = 'enrollment_installments';

    public function __construct(private AuditLogger $audit) {}

    /** Costo de matrícula configurado, o null si falta o no es positivo. */
    public function enrollmentCost(): ?float
    {
        $cost = SystemSetting::getValue(self::COST_SETTING);

        return is_numeric($cost) && $cost > 0 ? round((float) $cost, 2) : null;
    }

    public function installmentCount(): int
    {
        return max(1, (int) SystemSetting::getValue(self::INSTALLMENTS_SETTING, 1));
    }

    /**
     * Saldo real: costo − pagos validados (nunca negativo).
     * Con $except, ese pago no se cuenta (para revisar un pago que ya estaba validado).
     */
    public function outstandingBalance(Enrollment $enrollment, ?Payment $except = null): float
    {
        $validated = (float) $enrollment->validatedPayments()
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->sum('amount');

        return max(0, round($this->enrollmentCost() - $validated, 2));
    }

    /**
     * Cuánto se puede registrar todavía: costo − validados − pendientes.
     * Evita sobrepagos con varios pagos pendientes a la vez. Con $except, ese
     * pago no se cuenta (para corregir el monto de un pago pendiente).
     */
    public function availableToRegister(Enrollment $enrollment, ?Payment $except = null): float
    {
        $pending = (float) $enrollment->payments()
            ->where('status', Payment::STATUS_PENDING)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->sum('amount');

        return max(0, round($this->outstandingBalance($enrollment) - $pending, 2));
    }

    /**
     * Cuotas iguales del costo; la última absorbe el redondeo.
     *
     * @return list<array{number: int, amount: float}>
     */
    public function installmentPlan(): array
    {
        $cost = $this->enrollmentCost();
        $count = $this->installmentCount();
        $base = floor($cost / $count * 100) / 100;

        $plan = [];
        for ($i = 1; $i <= $count; $i++) {
            $amount = $i === $count ? round($cost - $base * ($count - 1), 2) : $base;
            $plan[] = ['number' => $i, 'amount' => $amount];
        }

        return $plan;
    }

    /** @param array{enrollment_id: int, amount: float|string, payment_method?: ?string, receipt_url?: ?string, installment_number?: ?int} $data */
    public function register(User $student, array $data): Payment
    {
        $enrollment = Enrollment::findOrFail($data['enrollment_id']);
        $balance = $this->outstandingBalance($enrollment);

        return Payment::create([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment->id,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'] ?? null,
            'receipt_url' => $data['receipt_url'] ?? null,
            'installment_number' => $data['installment_number'] ?? $this->nextInstallmentNumber($enrollment),
            'total_installments' => $this->installmentCount(),
            'installment_plan' => $this->installmentPlan(),
            'balance' => $balance,
            'partial' => (float) $data['amount'] < $balance,
            'paid_date' => now()->toDateString(),
            'currency' => 'PEN',
            'status' => Payment::STATUS_PENDING, // todo pago nace pendiente hasta que se revisa
        ]);
    }

    /** Corrección de un pago pendiente: solo los campos que envía el estudiante. */
    public function correct(Payment $payment, array $data): Payment
    {
        $payment->fill($data);

        if (array_key_exists('amount', $data)) {
            $payment->partial = (float) $payment->amount < (float) $payment->balance;
        }

        $payment->save();

        return $payment;
    }

    /**
     * Validar o rechazar un pago (RF21). Guarda quién y cuándo, recalcula el saldo
     * de los pagos de la matrícula y deja constancia en audit_logs.
     */
    public function review(Payment $payment, User $reviewer, string $status, ?string $reason = null): Payment
    {
        return DB::transaction(function () use ($payment, $reviewer, $status, $reason) {
            $previous = $payment->status;

            $payment->fill([
                'status' => $status,
                'validated_by' => $reviewer->id,
                'validated_at' => now(),
                'rejection_reason' => $status === Payment::STATUS_REJECTED ? $reason : null,
            ])->save();

            if ($payment->enrollment) {
                $this->refreshBalances($payment->enrollment, $payment);
            }

            $this->audit->record(
                $reviewer,
                $payment->student_id,
                $status === Payment::STATUS_VALIDATED ? AuditLogger::PAYMENT_VALIDATED : AuditLogger::PAYMENT_REJECTED,
                $reason,
                [
                    'payment_id' => $payment->id,
                    'enrollment_id' => $payment->enrollment_id,
                    'amount' => (float) $payment->amount,
                    'previous_status' => $previous,
                    'new_status' => $status,
                ],
            );

            return $payment->refresh();
        });
    }

    /**
     * Tras una revisión cambia la suma validada: se actualiza el saldo del pago
     * revisado y de los que siguen pendientes. Los demás conservan el saldo que
     * tenían cuando se revisaron (historial, RF22).
     */
    private function refreshBalances(Enrollment $enrollment, Payment $reviewed): void
    {
        if ($this->enrollmentCost() === null) {
            return;
        }

        $balance = $this->outstandingBalance($enrollment);

        $enrollment->payments()
            ->where(fn ($q) => $q->where('status', Payment::STATUS_PENDING)->orWhere('id', $reviewed->id))
            ->update(['balance' => $balance]);
    }

    private function nextInstallmentNumber(Enrollment $enrollment): int
    {
        $used = $enrollment->payments()->where('status', '!=', Payment::STATUS_REJECTED)->count();

        return min($used + 1, $this->installmentCount());
    }
}
