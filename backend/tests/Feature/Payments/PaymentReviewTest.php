<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Validar o rechazar pagos y su registro en audit_logs. RF19, RF21. */
class PaymentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private Enrollment $enrollment;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::setValue(PaymentService::COST_SETTING, 500, 'decimal');

        $this->coordinator = User::factory()->coordinator()->create();
        $this->enrollment = Enrollment::factory()->create();
        $this->payment = Payment::factory()
            ->for($this->enrollment->student, 'student')
            ->for($this->enrollment)
            ->create(['amount' => 200, 'balance' => 500]);
    }

    private function review(array $data)
    {
        return $this->actingAs($this->coordinator)->putJson("/api/payments/{$this->payment->id}/validate", $data);
    }

    public function test_validar_guarda_quien_y_cuando(): void
    {
        $this->review(['status' => Payment::STATUS_VALIDATED])
            ->assertOk()
            ->assertJsonPath('status', Payment::STATUS_VALIDATED)
            ->assertJsonPath('validated_by', $this->coordinator->id)
            ->assertJsonPath('rejection_reason', null);

        $this->assertNotNull($this->payment->fresh()->validated_at);
    }

    public function test_validar_recalcula_el_saldo(): void
    {
        $pending = Payment::factory()->for($this->enrollment->student, 'student')->for($this->enrollment)
            ->create(['amount' => 50, 'balance' => 500]);

        $this->review(['status' => Payment::STATUS_VALIDATED])
            ->assertOk()
            ->assertJsonPath('balance', 300); // 500 − 200

        $this->assertEquals(300, $pending->fresh()->balance);
    }

    public function test_rechazar_exige_motivo(): void
    {
        $this->review(['status' => Payment::STATUS_REJECTED])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason' => 'Indica el motivo del rechazo del pago.']);

        $this->assertSame(Payment::STATUS_PENDING, $this->payment->fresh()->status);
    }

    public function test_rechazar_guarda_el_motivo(): void
    {
        $this->review(['status' => Payment::STATUS_REJECTED, 'rejection_reason' => 'El comprobante no corresponde'])
            ->assertOk()
            ->assertJsonPath('status', Payment::STATUS_REJECTED)
            ->assertJsonPath('rejection_reason', 'El comprobante no corresponde')
            ->assertJsonPath('validated_by', $this->coordinator->id);
    }

    public function test_solo_se_aceptan_los_estados_validated_y_rejected(): void
    {
        foreach (['pending', 'completed', 'failed', 'refunded'] as $status) {
            $this->review(['status' => $status])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
        }
    }

    public function test_cada_validacion_queda_en_audit_logs(): void
    {
        $this->review(['status' => Payment::STATUS_VALIDATED])->assertOk();

        $log = AuditLog::sole();
        $this->assertSame(AuditLogger::PAYMENT_VALIDATED, $log->action);
        $this->assertSame($this->coordinator->id, $log->admin_id);
        $this->assertSame($this->payment->student_id, $log->user_id);
        $this->assertNull($log->reason);
        $this->assertSame([
            'payment_id' => $this->payment->id,
            'enrollment_id' => $this->enrollment->id,
            'amount' => 200,
            'previous_status' => Payment::STATUS_PENDING,
            'new_status' => Payment::STATUS_VALIDATED,
        ], $log->metadata);
    }

    public function test_cada_rechazo_queda_en_audit_logs_con_su_motivo(): void
    {
        $this->review(['status' => Payment::STATUS_REJECTED, 'rejection_reason' => 'Monto no coincide'])->assertOk();

        $log = AuditLog::sole();
        $this->assertSame(AuditLogger::PAYMENT_REJECTED, $log->action);
        $this->assertSame('Monto no coincide', $log->reason);
        $this->assertSame($this->coordinator->id, $log->admin_id);
    }

    public function test_un_intento_sin_permiso_no_deja_registro(): void
    {
        $this->actingAs($this->enrollment->student)
            ->putJson("/api/payments/{$this->payment->id}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertForbidden();

        $this->assertSame(0, AuditLog::count());
    }
}
