<?php

namespace Tests\Feature\Access;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PaymentPolicy y validación de pagos (Form Requests). RF17–RF22, RNF11. */
class PaymentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;

    private User $studentB;

    private Payment $paymentA;

    private Payment $paymentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studentA = User::factory()->student()->withCompletedProfile()->create();
        $this->studentB = User::factory()->student()->withCompletedProfile()->create();
        $this->paymentA = Payment::factory()->for($this->studentA, 'student')->create();
        $this->paymentB = Payment::factory()->for($this->studentB, 'student')->create();
    }

    // ---------- Ver ----------

    public function test_el_estudiante_a_no_puede_ver_el_pago_de_b(): void
    {
        $this->actingAs($this->studentA)
            ->getJson("/api/payments/{$this->paymentB->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes acceso a este pago.');
    }

    public function test_el_historial_del_estudiante_solo_trae_sus_pagos(): void
    {
        $ids = $this->actingAs($this->studentA)->getJson('/api/payments')->assertOk()->json('data.*.id');

        $this->assertSame([$this->paymentA->id], $ids);
    }

    public function test_admin_y_coordinador_ven_todos_los_pagos(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->coordinator()->create()] as $staff) {
            $this->actingAs($staff)->getJson('/api/payments')->assertOk()->assertJsonCount(2, 'data');
            $this->actingAs($staff)->getJson("/api/payments/{$this->paymentB->id}")->assertOk();
        }
    }

    // ---------- Registrar ----------

    public function test_el_estudiante_registra_un_pago_a_su_nombre(): void
    {
        $this->actingAs($this->studentA)
            ->postJson('/api/payments', [
                'amount' => 150,
                'student_id' => $this->studentB->id, // se ignora
                'status' => 'completed',             // se ignora
            ])
            ->assertCreated()
            ->assertJsonPath('student_id', $this->studentA->id)
            ->assertJsonPath('status', 'pending');
    }

    public function test_el_monto_es_obligatorio(): void
    {
        $this->actingAs($this->studentA)
            ->postJson('/api/payments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_no_se_paga_la_matricula_de_otro_estudiante(): void
    {
        $enrollmentB = Enrollment::factory()->for($this->studentB, 'student')->create();

        $this->actingAs($this->studentA)
            ->postJson('/api/payments', ['amount' => 150, 'enrollment_id' => $enrollmentB->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'La matrícula indicada no existe o no te pertenece.']);
    }

    public function test_el_estudiante_paga_su_propia_matricula(): void
    {
        $enrollmentA = Enrollment::factory()->for($this->studentA, 'student')->create();

        $this->actingAs($this->studentA)
            ->postJson('/api/payments', ['amount' => 150, 'enrollment_id' => $enrollmentA->id])
            ->assertCreated()
            ->assertJsonPath('enrollment_id', $enrollmentA->id);
    }

    public function test_el_personal_no_registra_pagos(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/payments', ['amount' => 150])
            ->assertForbidden();
    }

    // ---------- Editar y eliminar ----------

    public function test_el_estudiante_a_no_puede_editar_ni_eliminar_el_pago_de_b(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/payments/{$this->paymentB->id}", ['amount' => 1])
            ->assertForbidden();

        $this->actingAs($this->studentA)
            ->deleteJson("/api/payments/{$this->paymentB->id}")
            ->assertForbidden();

        $this->assertModelExists($this->paymentB);
    }

    public function test_el_estudiante_corrige_su_pago_pendiente_pero_no_su_estado(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/payments/{$this->paymentA->id}", ['amount' => 200, 'status' => 'completed'])
            ->assertOk();

        $fresh = $this->paymentA->fresh();
        $this->assertEquals(200, $fresh->amount);
        $this->assertSame('pending', $fresh->status);
    }

    public function test_un_pago_validado_ya_no_se_edita(): void
    {
        $completed = Payment::factory()->completed()->for($this->studentA, 'student')->create();

        $this->actingAs($this->studentA)
            ->putJson("/api/payments/{$completed->id}", ['amount' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes modificar un pago que ya fue validado.');
    }

    public function test_el_monto_no_se_puede_vaciar(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/payments/{$this->paymentA->id}", ['amount' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_el_admin_elimina_cualquier_pago(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson("/api/payments/{$this->paymentB->id}")
            ->assertOk();

        $this->assertModelMissing($this->paymentB);
    }

    // ---------- Validar ----------

    public function test_el_estudiante_no_valida_su_pago(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/payments/{$this->paymentA->id}/validate", ['status' => 'completed'])
            ->assertForbidden();

        $this->assertSame('pending', $this->paymentA->fresh()->status);
    }

    public function test_el_docente_no_valida_pagos(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->putJson("/api/payments/{$this->paymentA->id}/validate", ['status' => 'completed'])
            ->assertForbidden();
    }

    public function test_el_coordinador_valida_un_pago(): void
    {
        $this->actingAs(User::factory()->coordinator()->create())
            ->putJson("/api/payments/{$this->paymentA->id}/validate", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
    }

    public function test_el_estado_de_validacion_debe_ser_valido(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->putJson("/api/payments/{$this->paymentA->id}/validate", ['status' => 'aprobadisimo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
