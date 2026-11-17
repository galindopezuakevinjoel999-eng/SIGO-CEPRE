<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Reglas de negocio de los pagos (RF17–RF22) con las modalidades del CEPRE.
 *
 * Cada ciclo define sus modalidades de pago (semesters.payment_plans), con
 * cuotas que pueden ser desiguales. Ordinario II 2026:
 *   - unico:       S/ 600 en una cuota.
 *   - fraccionado: S/ 650 en dos cuotas, S/ 500 y S/ 150.
 * El estudiante elige la modalidad con su primer pago y queda fija.
 *
 * Lo que calcula el sistema:
 *  - número y monto de cada cuota: la siguiente que falta (las rechazadas no cuentan);
 *  - balance: total de la modalidad − pagos validados. Mientras no elige
 *    modalidad, se usa la más barata (lo mínimo que debe);
 *  - installment_plan, total_installments, partial, paid_date.
 *
 * Si el ciclo no tiene modalidades, se usa el costo global (enrollment_cost y
 * enrollment_installments de system_settings) como una modalidad "unico" en
 * cuotas iguales, por compatibilidad.
 */
class PaymentService
{
    /** Clave en system_settings con el costo global (respaldo si el ciclo no tiene modalidades). */
    public const COST_SETTING = 'enrollment_cost';

    /** Número de cuotas del costo global (respaldo). */
    public const INSTALLMENTS_SETTING = 'enrollment_installments';

    public function __construct(private AuditLogger $audit, private PrivateFiles $files) {}

    /**
     * Modalidades de pago de un ciclo.
     *
     * @return array<string, array{label: string, installments: list<float>, total: float}>
     */
    public function plansFor(?Semester $semester): array
    {
        $plans = $semester?->payment_plans;

        if (! $plans) {
            return $this->globalPlan();
        }

        return collect($plans)
            ->map(function (array $plan) {
                $installments = array_map(fn ($a) => round((float) $a, 2), $plan['installments'] ?? []);

                return ['label' => $plan['label'] ?? '', 'installments' => $installments, 'total' => round(array_sum($installments), 2)];
            })
            ->filter(fn ($plan) => $plan['total'] > 0)
            ->all();
    }

    /**
     * Modalidad de la matrícula: la elegida o, si aún no elige, la más barata.
     *
     * @return array{key: string, label: string, installments: list<float>, total: float}|null
     */
    public function planFor(Enrollment $enrollment): ?array
    {
        $plans = $this->plansFor($enrollment->semester);

        if (! $plans) {
            return null;
        }

        $key = $enrollment->payment_modality && isset($plans[$enrollment->payment_modality])
            ? $enrollment->payment_modality
            : collect($plans)->sortBy('total')->keys()->first();

        return ['key' => $key, ...$plans[$key]];
    }

    /** Total a pagar por la matrícula, o null si no hay modalidades configuradas. */
    public function costFor(Enrollment $enrollment): ?float
    {
        return $this->planFor($enrollment)['total'] ?? null;
    }

    /**
     * Saldo real: total de la modalidad − pagos validados (nunca negativo).
     * Con $except, ese pago no se cuenta (para revisar un pago que ya estaba validado).
     */
    public function outstandingBalance(Enrollment $enrollment, ?Payment $except = null): float
    {
        $validated = (float) $enrollment->validatedPayments()
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->sum('amount');

        return max(0, round(($this->costFor($enrollment) ?? 0) - $validated, 2));
    }

    /**
     * Siguiente cuota que falta registrar en la modalidad indicada (o en la de
     * la matrícula). Las cuotas pendientes de validación ya cuentan como
     * registradas; las rechazadas no. Null si ya registró todas.
     *
     * @return array{number: int, amount: float, total_installments: int}|null
     */
    public function nextInstallment(Enrollment $enrollment, ?string $modality = null): ?array
    {
        $plans = $this->plansFor($enrollment->semester);
        $plan = $plans[$modality ?? $enrollment->payment_modality] ?? $this->planFor($enrollment);

        if (! $plan) {
            return null;
        }

        $registered = $enrollment->payments()->where('status', '!=', Payment::STATUS_REJECTED)->count();

        if ($registered >= count($plan['installments'])) {
            return null;
        }

        return [
            'number' => $registered + 1,
            'amount' => $plan['installments'][$registered],
            'total_installments' => count($plan['installments']),
        ];
    }

    /**
     * Registra la siguiente cuota. La primera vez fija la modalidad elegida.
     *
     * @param  array{enrollment_id: int, amount: float|string, receipt: UploadedFile, receipt_number: string, modality?: ?string, payment_method?: ?string}  $data
     */
    public function register(User $student, array $data): Payment
    {
        return DB::transaction(function () use ($student, $data) {
            $enrollment = Enrollment::lockForUpdate()->findOrFail($data['enrollment_id']);

            if (! $enrollment->payment_modality) {
                $enrollment->forceFill(['payment_modality' => $data['modality']])->save();
            }

            $plan = $this->planFor($enrollment);
            $installment = $this->nextInstallment($enrollment);
            $balance = $this->outstandingBalance($enrollment);
            $receipt = $this->files->store($data['receipt'], "payments/{$enrollment->id}");

            return Payment::create([
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'] ?? null,
                'receipt_path' => $receipt['path'],
                'receipt_name' => $receipt['name'],
                'receipt_number' => $data['receipt_number'],
                'installment_number' => $installment['number'],
                'total_installments' => $installment['total_installments'],
                'installment_plan' => array_map(
                    fn ($amount, $i) => ['number' => $i + 1, 'amount' => $amount],
                    $plan['installments'],
                    array_keys($plan['installments']),
                ),
                'balance' => $balance,
                'partial' => (float) $data['amount'] < $balance,
                'paid_date' => now()->toDateString(),
                'currency' => 'PEN',
                'status' => Payment::STATUS_PENDING, // todo pago nace pendiente hasta que se revisa
            ]);
        });
    }

    /**
     * Corrección de un pago pendiente: método, N° de recibo o comprobante.
     * El monto no se corrige: es el de la cuota. Un comprobante nuevo
     * reemplaza al anterior, que se borra del disco.
     */
    public function correct(Payment $payment, array $data): Payment
    {
        $previousReceipt = null;

        if (($data['receipt'] ?? null) instanceof UploadedFile) {
            $previousReceipt = $payment->receipt_path;
            $receipt = $this->files->store($data['receipt'], "payments/{$payment->enrollment_id}");
            $payment->receipt_path = $receipt['path'];
            $payment->receipt_name = $receipt['name'];
        }
        unset($data['receipt']);

        $payment->fill($data)->save();
        $this->files->delete($previousReceipt);

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
                    'receipt_number' => $payment->receipt_number,
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
        if ($this->costFor($enrollment) === null) {
            return;
        }

        $balance = $this->outstandingBalance($enrollment);

        $enrollment->payments()
            ->where(fn ($q) => $q->where('status', Payment::STATUS_PENDING)->orWhere('id', $reviewed->id))
            ->update(['balance' => $balance]);
    }

    /** Respaldo: el costo global de system_settings como modalidad única en cuotas iguales. */
    private function globalPlan(): array
    {
        $cost = SystemSetting::getValue(self::COST_SETTING);

        if (! is_numeric($cost) || $cost <= 0) {
            return [];
        }

        $cost = round((float) $cost, 2);
        $count = max(1, (int) SystemSetting::getValue(self::INSTALLMENTS_SETTING, 1));
        $base = floor($cost / $count * 100) / 100;
        $installments = [];
        for ($i = 1; $i <= $count; $i++) {
            $installments[] = $i === $count ? round($cost - $base * ($count - 1), 2) : $base; // la última absorbe el redondeo
        }

        return ['unico' => ['label' => 'Pago único', 'installments' => $installments, 'total' => $cost]];
    }
}
