<?php

namespace App\Reports;

use App\Models\Payment;
use App\Reports\Support\ReportDocument;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportQueries;
use App\Reports\Support\ReportTable;

/** Reporte de pagos (RF39): pagados, deudores con saldo, parciales vs completos e ingresos. */
class PaymentReport
{
    public const STATUS_LABELS = ['pagado' => 'Pagado', 'parcial' => 'Parcial', 'sin_pago' => 'Sin pago'];

    public function __construct(private ReportQueries $queries) {}

    public function build(ReportFilters $filters): ReportDocument
    {
        $plansLabel = $this->queries->paymentPlansLabel($filters);
        $situation = $this->queries->paymentStatusByEnrollment($filters);

        $validatedPayments = $this->queries->payments($filters)->where('status', Payment::STATUS_VALIDATED);
        $income = round((float) (clone $validatedPayments)->sum('amount'), 2);
        $partialPayments = (clone $validatedPayments)->where('partial', true)->count();
        $completePayments = (clone $validatedPayments)->where('partial', false)->count();

        $rows = $situation->map(fn ($s) => [
            'code' => $s['enrollment']->enrollment_code,
            'student' => $s['enrollment']->student?->name,
            'dni' => $s['enrollment']->student?->dni,
            'career' => $s['enrollment']->career?->name,
            'enrollment_status' => $s['enrollment']->status === 'approved' ? 'Aprobada' : 'Pendiente',
            'modality' => $s['enrollment']->payment_modality ?? '(sin elegir)',
            'cost' => $s['cost'],
            'validated' => $s['validated'],
            'pending' => $s['pending'],
            'balance' => $s['balance'],
            'status' => self::STATUS_LABELS[$s['status']],
        ])->values()->all();

        $debtors = array_values(array_filter($rows, fn ($r) => ($r['balance'] ?? 0) > 0));

        $notes = $plansLabel === null
            ? ['Los montos de pago del ciclo no están configurados: no se puede calcular saldos ni deudores.']
            : ['Saldo = total de la modalidad elegida − pagos validados. Sin modalidad elegida se usa la de menor costo. Los pagos pendientes no reducen el saldo hasta validarse.'];

        return new ReportDocument(
            title: 'Reporte de pagos',
            filters: $filters->describe(),
            summary: [
                'Ingresos (pagos validados)' => ReportQueries::money($income),
                'Modalidades de pago' => $plansLabel,
                'Matrículas al día (pagado)' => $situation->where('status', 'pagado')->count(),
                'Matrículas con pago parcial' => $situation->where('status', 'parcial')->count(),
                'Matrículas sin pago' => $situation->where('status', 'sin_pago')->count(),
                'Deudores' => $plansLabel === null ? null : count($debtors),
                'Deuda total' => $plansLabel === null ? null : ReportQueries::money(array_sum(array_column($debtors, 'balance'))),
                'Pagos validados parciales (cuotas)' => $partialPayments,
                'Pagos validados completos' => $completePayments,
            ],
            tables: [
                new ReportTable('Situación de pago por matrícula', [
                    'code' => 'Código', 'student' => 'Estudiante', 'dni' => 'DNI', 'career' => 'Carrera',
                    'enrollment_status' => 'Matrícula', 'modality' => 'Modalidad', 'cost' => 'Total (S/)', 'validated' => 'Pagado (S/)', 'pending' => 'Por validar (S/)',
                    'balance' => 'Saldo (S/)', 'status' => 'Estado',
                ], $rows),
                new ReportTable('Deudores', [
                    'code' => 'Código', 'student' => 'Estudiante', 'dni' => 'DNI', 'career' => 'Carrera',
                    'validated' => 'Pagado (S/)', 'balance' => 'Saldo (S/)',
                ], $debtors),
            ],
            notes: $notes,
        );
    }
}
