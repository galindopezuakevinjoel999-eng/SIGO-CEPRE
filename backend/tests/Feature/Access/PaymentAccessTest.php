<?php

namespace Tests\Feature\Access;

use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PaymentPolicy: quién ve, registra, corrige, elimina y valida pagos. RNF11. */
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

        SystemSetting::setValue(PaymentService::COST_SETTING, 500, 'decimal');

        $this->studentA = User::factory()->student()->withCompletedProfile()->create();
        $this->studentB = User::factory()->student()->withCompletedProfile()->create();
        $this->paymentA = Payment::factory()->for($this->studentA, 'student')->create(['amount' => 100]);
        $this->paymentB = Payment::factory()->for($this->studentB, 'student')->create(['amount' => 100]);
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

    public function test_el_personal_no_registra_pagos(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/payments', ['amount' => 150, 'enrollment_id' => $this->paymentA->enrollment_id])
            ->assertForbidden();
    }

    // ---------- Corregir y eliminar ----------

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
            ->putJson("/api/payments/{$this->paymentA->id}", ['receipt_number' => 'rc 000777', 'status' => Payment::STATUS_VALIDATED])
            ->assertOk();

        $fresh = $this->paymentA->fresh();
        $this->assertSame('RC000777', $fresh->receipt_number);
        $this->assertSame(Payment::STATUS_PENDING, $fresh->status);
    }

    public function test_un_pago_ya_revisado_no_se_edita(): void
    {
        foreach ([Payment::factory()->validated(), Payment::factory()->rejected()] as $factory) {
            $reviewed = $factory->for($this->studentA, 'student')->create();

            $this->actingAs($this->studentA)
                ->putJson("/api/payments/{$reviewed->id}", ['amount' => 1])
                ->assertForbidden()
                ->assertJsonPath('message', 'No puedes modificar un pago que ya fue revisado (validado o rechazado).');
        }
    }

    public function test_el_monto_no_se_corrige_es_el_de_la_cuota(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/payments/{$this->paymentA->id}", ['amount' => 1])
            ->assertOk();

        $this->assertEquals(100, $this->paymentA->fresh()->amount);
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
            ->putJson("/api/payments/{$this->paymentA->id}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertForbidden();

        $this->assertSame(Payment::STATUS_PENDING, $this->paymentA->fresh()->status);
    }

    public function test_el_docente_no_valida_pagos(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->putJson("/api/payments/{$this->paymentA->id}/validate", ['status' => Payment::STATUS_VALIDATED])
            ->assertForbidden();
    }
}
