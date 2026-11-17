<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sobrepago al validar: un pago no puede validarse si excede el saldo real
 * (total de la modalidad − otros pagos validados). Al registrar ya no hay
 * sobrepago posible: cada pago debe ser exactamente su cuota (ver
 * PaymentRegistrationTest); esto cubre datos anteriores a esa regla.
 *
 * Modalidad: único S/ 600.
 */
class PaymentOverpaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $semester = Semester::factory()->active()->withCeprePaymentPlans()->create();
        $this->student = User::factory()->student()->withCompletedProfile()->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'student')->for($semester)->create();
        $this->enrollment->forceFill(['payment_modality' => 'unico'])->save();
        $this->coordinator = User::factory()->coordinator()->create();
    }

    private function existing(string $status, float $amount): Payment
    {
        return Payment::factory()->state(['status' => $status, 'amount' => $amount])
            ->for($this->student, 'student')
            ->for($this->enrollment)
            ->create();
    }

    private function validate(Payment $payment)
    {
        return $this->actingAs($this->coordinator)->putJson("/api/payments/{$payment->id}/validate", ['status' => Payment::STATUS_VALIDATED]);
    }

    public function test_validar_rechaza_un_pago_que_excede_el_saldo_real(): void
    {
        // Dos pendientes que suman más que el total (datos anteriores a la regla de cuotas exactas).
        $first = $this->existing(Payment::STATUS_PENDING, 400);
        $second = $this->existing(Payment::STATUS_PENDING, 400);

        $this->validate($first)->assertOk();

        $this->validate($second)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede validar: el pago (S/ 400.00) excede el saldo real de la matrícula (S/ 200.00). Recházalo o pide al estudiante que lo corrija.');

        $this->assertSame(Payment::STATUS_PENDING, $second->fresh()->status);
        $this->assertSame(1, AuditLog::count(), 'El intento fallido no se audita');
    }

    public function test_el_saldo_real_depende_de_la_modalidad(): void
    {
        // Fraccionado (650): con 500 validados quedan 150; un pago de 150 sí cabe.
        $this->enrollment->forceFill(['payment_modality' => 'fraccionado'])->save();
        $this->existing(Payment::STATUS_VALIDATED, 500);

        $this->validate($this->existing(Payment::STATUS_PENDING, 150))->assertOk();
    }

    public function test_el_pago_que_excede_se_puede_rechazar(): void
    {
        $this->existing(Payment::STATUS_VALIDATED, 600);
        $extra = $this->existing(Payment::STATUS_PENDING, 50);

        $this->actingAs($this->coordinator)
            ->putJson("/api/payments/{$extra->id}/validate", [
                'status' => Payment::STATUS_REJECTED,
                'rejection_reason' => 'La matrícula ya estaba pagada',
            ])
            ->assertOk();
    }

    public function test_revalidar_un_pago_ya_validado_no_se_cuenta_a_si_mismo(): void
    {
        $payment = $this->existing(Payment::STATUS_VALIDATED, 600);

        $this->validate($payment)->assertOk();
    }
}
