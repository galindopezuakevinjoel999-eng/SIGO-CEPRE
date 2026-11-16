<?php

namespace Tests\Feature\Payments;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registro de pagos: qué envía el estudiante y qué calcula el sistema.
 * RF17, RF18, RF20.
 */
class PaymentRegistrationTest extends TestCase
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

    private function pay(array $data)
    {
        return $this->actingAs($this->student)->postJson('/api/payments', [
            'enrollment_id' => $this->enrollment->id,
            ...$data,
        ]);
    }

    public function test_la_matricula_es_obligatoria(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/payments', ['amount' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'Indica la matrícula que estás pagando.']);
    }

    public function test_no_se_paga_la_matricula_de_otro_estudiante(): void
    {
        $other = Enrollment::factory()->create();

        $this->actingAs($this->student)
            ->postJson('/api/payments', ['amount' => 100, 'enrollment_id' => $other->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'La matrícula indicada no existe o no te pertenece.']);
    }

    public function test_el_monto_es_obligatorio_y_positivo(): void
    {
        $this->pay([])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->pay(['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors(['amount' => 'El monto debe ser mayor que cero.']);
    }

    public function test_el_sistema_asigna_saldo_plan_fecha_y_estado(): void
    {
        $this->pay([
            'amount' => 200,
            'payment_method' => 'yape',
            'receipt_url' => 'comprobantes/123.jpg',
        ])
            ->assertCreated()
            ->assertJsonPath('student_id', $this->student->id)
            ->assertJsonPath('status', Payment::STATUS_PENDING)
            ->assertJsonPath('balance', 500)              // 500 de costo − 0 validado
            ->assertJsonPath('partial', true)             // 200 < 500
            ->assertJsonPath('paid_date', now()->startOfDay()->toJSON())
            ->assertJsonPath('installment_number', 1)
            ->assertJsonPath('total_installments', 1)
            ->assertJsonPath('installment_plan', [['number' => 1, 'amount' => 500]]);
    }

    public function test_los_campos_calculados_que_envia_el_estudiante_se_ignoran(): void
    {
        $other = User::factory()->student()->create();

        $payment = $this->pay([
            'amount' => 100,
            'student_id' => $other->id,
            'status' => Payment::STATUS_VALIDATED,
            'balance' => 0,
            'installment_plan' => json_encode([['number' => 1, 'amount' => 1]]),
            'paid_date' => '2020-01-01',
            'partial' => false,
            'validated_by' => $this->student->id,
        ])->assertCreated()->json();

        $this->assertSame($this->student->id, $payment['student_id']);
        $this->assertSame(Payment::STATUS_PENDING, $payment['status']);
        $this->assertEquals(500, $payment['balance']);
        $this->assertTrue($payment['partial']);
        $this->assertNull(Payment::find($payment['id'])->validated_by);
        $this->assertSame([['number' => 1, 'amount' => 500]], $payment['installment_plan']);
        $this->assertStringStartsWith(now()->toDateString(), $payment['paid_date']);
    }

    public function test_el_saldo_descuenta_solo_los_pagos_validados(): void
    {
        Payment::factory()->validated()->for($this->student, 'student')->for($this->enrollment)->create(['amount' => 150]);
        Payment::factory()->for($this->student, 'student')->for($this->enrollment)->create(['amount' => 100]);  // pendiente: no cuenta
        Payment::factory()->rejected()->for($this->student, 'student')->for($this->enrollment)->create(['amount' => 80]); // rechazado: no cuenta

        // El saldo (deuda real) solo descuenta lo validado: 500 − 150 = 350.
        // Lo que se puede registrar descuenta además lo pendiente: 350 − 100 = 250.
        $this->pay(['amount' => 250])
            ->assertCreated()
            ->assertJsonPath('balance', 350)
            ->assertJsonPath('partial', true); // 250 < 350
    }

    public function test_el_monto_no_puede_superar_el_saldo(): void
    {
        Payment::factory()->validated()->for($this->student, 'student')->for($this->enrollment)->create(['amount' => 400]);

        $this->pay(['amount' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'El monto supera lo que falta pagar (S/ 100.00), considerando tus pagos pendientes de validación.']);
    }

    public function test_no_se_paga_una_matricula_ya_pagada(): void
    {
        Payment::factory()->validated()->for($this->student, 'student')->for($this->enrollment)->create(['amount' => 500]);

        $this->pay(['amount' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'Esta matrícula ya está pagada por completo.']);
    }

    public function test_sin_costo_configurado_no_se_aceptan_pagos(): void
    {
        SystemSetting::where('key', PaymentService::COST_SETTING)->delete();

        $this->pay(['amount' => 100])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'El costo de matrícula aún no está configurado. Comunícate con la administración.']);
    }

    public function test_plan_en_cuotas_y_numero_de_cuota(): void
    {
        SystemSetting::setValue(PaymentService::INSTALLMENTS_SETTING, 3, 'integer');

        $this->pay(['amount' => 166.66])
            ->assertCreated()
            ->assertJsonPath('total_installments', 3)
            ->assertJsonPath('installment_number', 1)
            ->assertJsonPath('installment_plan', [
                ['number' => 1, 'amount' => 166.66],
                ['number' => 2, 'amount' => 166.66],
                ['number' => 3, 'amount' => 166.68], // la última absorbe el redondeo
            ]);

        // Sin número de cuota, el sistema asigna la siguiente.
        $this->pay(['amount' => 100])->assertCreated()->assertJsonPath('installment_number', 2);

        // Fuera del rango de cuotas configuradas.
        $this->pay(['amount' => 100, 'installment_number' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('installment_number');
    }

    public function test_al_corregir_el_monto_se_recalcula_si_es_parcial(): void
    {
        $id = $this->pay(['amount' => 100])->assertCreated()->json('id');

        $this->actingAs($this->student)
            ->putJson("/api/payments/{$id}", ['amount' => 500, 'balance' => 0, 'enrollment_id' => 999])
            ->assertOk()
            ->assertJsonPath('partial', false)
            ->assertJsonPath('balance', 500)
            ->assertJsonPath('enrollment_id', $this->enrollment->id);
    }
}
