<?php

namespace Tests\Feature\Enrollments;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Aprobar o rechazar matrículas: requisito de pago validado y auditoría. RF16. */
class EnrollmentApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->enrollment = Enrollment::factory()->create();
    }

    private function decide(array $data)
    {
        return $this->actingAs($this->admin)->putJson("/api/enrollments/{$this->enrollment->id}/status", $data);
    }

    private function addPayment(string $state): void
    {
        Payment::factory()->state(['status' => $state])
            ->for($this->enrollment->student, 'student')
            ->for($this->enrollment)
            ->create();
    }

    public function test_no_se_aprueba_sin_pagos(): void
    {
        $this->decide(['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se puede aprobar la matrícula: aún no tiene ningún pago validado.')
            ->assertJsonValidationErrors('status');

        $this->assertSame('pending', $this->enrollment->fresh()->status);
    }

    public function test_no_se_aprueba_con_pagos_pendientes_o_rechazados(): void
    {
        $this->addPayment(Payment::STATUS_PENDING);
        $this->addPayment(Payment::STATUS_REJECTED);

        $this->decide(['status' => 'approved'])->assertUnprocessable();
    }

    public function test_un_pago_validado_de_otra_matricula_no_cuenta(): void
    {
        Payment::factory()->validated()->create(); // otra matrícula, otro estudiante

        $this->decide(['status' => 'approved'])->assertUnprocessable();
    }

    public function test_con_un_pago_validado_se_aprueba(): void
    {
        $this->addPayment(Payment::STATUS_VALIDATED);

        $this->decide(['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertNotNull($this->enrollment->fresh()->approved_at);
    }

    public function test_rechazar_no_exige_pago(): void
    {
        $this->decide(['status' => 'rejected', 'rejection_reason' => 'Documentos incompletos'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    public function test_la_aprobacion_queda_en_audit_logs(): void
    {
        $this->addPayment(Payment::STATUS_VALIDATED);

        $this->decide(['status' => 'approved'])->assertOk();

        $log = AuditLog::sole();
        $this->assertSame(AuditLogger::ENROLLMENT_APPROVED, $log->action);
        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertSame($this->enrollment->student_id, $log->user_id);
        $this->assertSame([
            'enrollment_id' => $this->enrollment->id,
            'previous_status' => 'pending',
            'new_status' => 'approved',
        ], $log->metadata);
    }

    public function test_el_rechazo_queda_en_audit_logs_con_su_motivo(): void
    {
        $this->decide(['status' => 'rejected', 'rejection_reason' => 'DNI ilegible'])->assertOk();

        $log = AuditLog::sole();
        $this->assertSame(AuditLogger::ENROLLMENT_REJECTED, $log->action);
        $this->assertSame('DNI ilegible', $log->reason);
        $this->assertSame($this->admin->id, $log->admin_id);
    }

    public function test_una_aprobacion_rechazada_por_falta_de_pago_no_deja_registro(): void
    {
        $this->decide(['status' => 'approved'])->assertUnprocessable();

        $this->assertSame(0, AuditLog::count());
    }
}
