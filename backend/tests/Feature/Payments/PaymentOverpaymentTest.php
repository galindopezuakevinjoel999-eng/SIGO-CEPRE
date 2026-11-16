<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sobrepagos (costo de matrícula = S/ 500).
 *  - Al registrar o corregir: monto ≤ costo − validados − pendientes.
 *  - Al validar: monto ≤ costo − otros pagos validados (saldo real).
 */
class PaymentOverpaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::setValue(PaymentService::COST_SETTING, 500, 'decimal');

        $this->student = User::factory()->student()->withCompletedProfile()->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'student')->create();
    }

    private function existing(string $status, float $amount): Payment
    {
        return Payment::factory()->state(['status' => $status, 'amount' => $amount])
            ->for($this->student, 'student')
            ->for($this->enrollment)
            ->create();
    }

    private function pay(float $amount)
    {
        return $this->actingAs($this->student)->postJson('/api/payments', [
            'enrollment_id' => $this->enrollment->id,
            'amount' => $amount,
        ]);
    }

    // ---------- Al registrar ----------

    public function test_los_pagos_pendientes_reducen_lo_que_se_puede_registrar(): void
    {
        $this->existing(Payment::STATUS_VALIDATED, 100);
        $this->existing(Payment::STATUS_PENDING, 300);   // disponible: 500 − 100 − 300 = 100

        $this->pay(150)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'amount' => 'El monto supera lo que falta pagar (S/ 100.00), considerando tus pagos pendientes de validación.',
            ]);

        $this->pay(100)->assertCreated();
    }

    public function test_dos_pagos_pendientes_no_pueden_sumar_mas_que_el_costo(): void
    {
        $this->pay(300)->assertCreated();
        $this->pay(300)->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertEquals(300, Payment::sum('amount'));
    }

    public function test_si_los_pendientes_cubren_todo_no_se_registra_nada_mas(): void
    {
        $this->existing(Payment::STATUS_PENDING, 500);

        $this->pay(1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'amount' => 'Tus pagos pendientes de validación ya cubren el saldo de esta matrícula. Espera a que sean revisados.',
            ]);
    }

    public function test_los_pagos_rechazados_no_ocupan_saldo(): void
    {
        $this->existing(Payment::STATUS_REJECTED, 500);

        $this->pay(500)->assertCreated();
    }

    // ---------- Al corregir ----------

    public function test_corregir_el_monto_no_puede_generar_sobrepago(): void
    {
        $this->existing(Payment::STATUS_PENDING, 200);
        $mine = $this->existing(Payment::STATUS_PENDING, 100); // disponible para este: 500 − 200 = 300

        $this->actingAs($this->student)
            ->putJson("/api/payments/{$mine->id}", ['amount' => 301])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        // Su propio monto anterior no cuenta en contra.
        $this->actingAs($this->student)
            ->putJson("/api/payments/{$mine->id}", ['amount' => 300])
            ->assertOk();
    }

    // ---------- Al validar ----------

    public function test_validar_rechaza_un_pago_que_excede_el_saldo_real(): void
    {
        // Dos pendientes que suman más que el costo (datos anteriores a esta regla).
        $first = $this->existing(Payment::STATUS_PENDING, 300);
        $second = $this->existing(Payment::STATUS_PENDING, 300);
        $coordinator = User::factory()->coordinator()->create();

        $this->actingAs($coordinator)
            ->putJson("/api/payments/{$first->id}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertOk();

        $this->actingAs($coordinator)
            ->putJson("/api/payments/{$second->id}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'No se puede validar: el pago (S/ 300.00) excede el saldo real de la matrícula (S/ 200.00). Recházalo o pide al estudiante que lo corrija.',
            );

        $this->assertSame(Payment::STATUS_PENDING, $second->fresh()->status);
        $this->assertSame(1, AuditLog::count(), 'El intento fallido no se audita');
    }

    public function test_el_pago_que_excede_se_puede_rechazar(): void
    {
        $this->existing(Payment::STATUS_VALIDATED, 500);
        $extra = $this->existing(Payment::STATUS_PENDING, 50);

        $this->actingAs(User::factory()->coordinator()->create())
            ->putJson("/api/payments/{$extra->id}/validate", [
                'status' => Payment::STATUS_REJECTED,
                'rejection_reason' => 'La matrícula ya estaba pagada',
            ])
            ->assertOk();
    }

    public function test_revalidar_un_pago_ya_validado_no_se_cuenta_a_si_mismo(): void
    {
        $payment = $this->existing(Payment::STATUS_VALIDATED, 500);

        $this->actingAs(User::factory()->admin()->create())
            ->putJson("/api/payments/{$payment->id}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertOk();
    }
}
