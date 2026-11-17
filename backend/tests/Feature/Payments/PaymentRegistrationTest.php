<?php

namespace Tests\Feature\Payments;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registro de pagos con las modalidades del CEPRE UNAJMA (RF17, RF18, RF20):
 * único S/ 600, o fraccionado S/ 650 en dos cuotas de S/ 500 y S/ 150.
 */
class PaymentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $semester = Semester::factory()->active()->withCeprePaymentPlans()->create();
        $this->student = User::factory()->student()->withCompletedProfile()->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'student')->for($semester)->create();
    }

    private function pay(array $data)
    {
        return $this->actingAs($this->student)->postJson('/api/payments', [
            'enrollment_id' => $this->enrollment->id,
            'receipt' => $this->fakePdf('recibo.pdf'),
            'receipt_number' => $this->receiptNumber(),
            ...$data,
        ]);
    }

    // ---------- Datos obligatorios ----------

    public function test_la_matricula_es_obligatoria_y_propia(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/payments', ['amount' => 600, 'modality' => 'unico', 'receipt' => $this->fakePdf(), 'receipt_number' => 'RC-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'Indica la matrícula que estás pagando.']);

        $this->pay(['enrollment_id' => Enrollment::factory()->create()->id, 'amount' => 600, 'modality' => 'unico'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'La matrícula indicada no existe o no te pertenece.']);
    }

    public function test_el_primer_pago_exige_elegir_la_modalidad(): void
    {
        $this->pay(['amount' => 600])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['modality' => 'Elige la modalidad de pago: unico (S/ 600.00) o fraccionado (S/ 650.00).']);

        $this->pay(['amount' => 600, 'modality' => 'becado'])->assertUnprocessable()->assertJsonValidationErrors('modality');
    }

    // ---------- Pago único ----------

    public function test_pago_unico_de_600_en_una_cuota(): void
    {
        $this->pay(['amount' => 600, 'modality' => 'unico', 'payment_method' => 'caja'])
            ->assertCreated()
            ->assertJsonPath('status', Payment::STATUS_PENDING)
            ->assertJsonPath('installment_number', 1)
            ->assertJsonPath('total_installments', 1)
            ->assertJsonPath('installment_plan', [['number' => 1, 'amount' => 600]])
            ->assertJsonPath('balance', 600)
            ->assertJsonPath('partial', false);

        $this->assertSame('unico', $this->enrollment->fresh()->payment_modality);
    }

    public function test_el_pago_unico_debe_ser_exactamente_600(): void
    {
        $this->pay(['amount' => 500, 'modality' => 'unico'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'La cuota 1 de 1 (Pago único) es de S/ 600.00.']);
    }

    // ---------- Pago fraccionado ----------

    public function test_pago_fraccionado_en_cuotas_de_500_y_150(): void
    {
        $this->pay(['amount' => 500, 'modality' => 'fraccionado'])
            ->assertCreated()
            ->assertJsonPath('installment_number', 1)
            ->assertJsonPath('total_installments', 2)
            ->assertJsonPath('installment_plan', [['number' => 1, 'amount' => 500], ['number' => 2, 'amount' => 150]])
            ->assertJsonPath('balance', 650)   // total de la modalidad, aún sin pagos validados
            ->assertJsonPath('partial', true);

        // La segunda cuota no necesita repetir la modalidad.
        $this->pay(['amount' => 150])
            ->assertCreated()
            ->assertJsonPath('installment_number', 2);
    }

    public function test_cada_cuota_tiene_su_monto(): void
    {
        $this->pay(['amount' => 325, 'modality' => 'fraccionado'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'La cuota 1 de 2 (Pago fraccionado) es de S/ 500.00.']);

        $this->pay(['amount' => 500, 'modality' => 'fraccionado'])->assertCreated();

        $this->pay(['amount' => 500])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount' => 'La cuota 2 de 2 (Pago fraccionado) es de S/ 150.00.']);
    }

    public function test_la_modalidad_elegida_no_se_puede_cambiar(): void
    {
        $this->pay(['amount' => 500, 'modality' => 'fraccionado'])->assertCreated();

        $this->pay(['amount' => 600, 'modality' => 'unico'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['modality' => 'Ya elegiste la modalidad "fraccionado" para esta matrícula; no se puede cambiar.']);
    }

    public function test_no_se_registran_mas_cuotas_que_las_de_la_modalidad(): void
    {
        $this->pay(['amount' => 500, 'modality' => 'fraccionado'])->assertCreated();
        $this->pay(['amount' => 150])->assertCreated();

        $this->pay(['amount' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'Ya registraste todas las cuotas de tu modalidad. Espera a que sean validadas.']);
    }

    public function test_una_cuota_rechazada_se_vuelve_a_registrar(): void
    {
        $first = Payment::find($this->pay(['amount' => 500, 'modality' => 'fraccionado'])->json('id'));
        $first->update(['status' => Payment::STATUS_REJECTED]);

        $this->pay(['amount' => 500])->assertCreated()->assertJsonPath('installment_number', 1);
    }

    // ---------- Saldo según la modalidad ----------

    public function test_el_saldo_depende_de_la_modalidad(): void
    {
        $service = app(PaymentService::class);

        // Sin elegir: se usa la más barata.
        $this->assertSame(600.0, $service->outstandingBalance($this->enrollment));

        Payment::factory()->validated()->for($this->student, 'student')->for($this->enrollment)->create(['amount' => 500]);
        $this->enrollment->forceFill(['payment_modality' => 'fraccionado'])->save();

        $this->assertSame(150.0, $service->outstandingBalance($this->enrollment->fresh()));
    }

    public function test_no_se_paga_una_matricula_ya_pagada(): void
    {
        $this->enrollment->forceFill(['payment_modality' => 'unico'])->save();
        Payment::factory()->validated()->for($this->student, 'student')->for($this->enrollment)->create(['amount' => 600]);

        $this->pay(['amount' => 600])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'Esta matrícula ya está pagada por completo.']);
    }

    public function test_los_campos_calculados_que_envia_el_estudiante_se_ignoran(): void
    {
        $other = User::factory()->student()->create();

        $payment = $this->pay([
            'amount' => 600,
            'modality' => 'unico',
            'student_id' => $other->id,
            'status' => Payment::STATUS_VALIDATED,
            'balance' => 0,
            'installment_number' => 9,
            'paid_date' => '2020-01-01',
            'validated_by' => $this->student->id,
        ])->assertCreated()->json();

        $this->assertSame($this->student->id, $payment['student_id']);
        $this->assertSame(Payment::STATUS_PENDING, $payment['status']);
        $this->assertEquals(600, $payment['balance']);
        $this->assertSame(1, $payment['installment_number']);
        $this->assertNull(Payment::find($payment['id'])->validated_by);
        $this->assertStringStartsWith(now()->toDateString(), $payment['paid_date']);
    }

    // ---------- N° de recibo de caja ----------

    public function test_el_numero_de_recibo_es_obligatorio(): void
    {
        $this->pay(['amount' => 600, 'modality' => 'unico', 'receipt_number' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt_number' => 'Indica el N° del recibo de caja de la UNAJMA.']);
    }

    public function test_un_recibo_solo_se_usa_una_vez_en_todo_el_sistema(): void
    {
        $this->pay(['amount' => 500, 'modality' => 'fraccionado', 'receipt_number' => '001-004512'])->assertCreated();

        // Otra cuota de la misma matrícula.
        $this->pay(['amount' => 150, 'receipt_number' => '001-004512'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt_number' => 'El recibo N° 001-004512 ya fue registrado en otro pago. Cada recibo de caja se usa una sola vez.']);

        // Otro estudiante.
        $other = User::factory()->student()->withCompletedProfile()->create();
        $otherEnrollment = Enrollment::factory()->for($other, 'student')->for($this->enrollment->semester)->create();
        $this->actingAs($other)->postJson('/api/payments', [
            'enrollment_id' => $otherEnrollment->id, 'amount' => 600, 'modality' => 'unico',
            'receipt' => $this->fakePdf(), 'receipt_number' => '001-004512',
        ])->assertUnprocessable()->assertJsonValidationErrors('receipt_number');
    }

    public function test_tambien_cuenta_un_recibo_de_un_pago_rechazado(): void
    {
        Payment::factory()->rejected()->create(['receipt_number' => '001-000099']);

        $this->pay(['amount' => 600, 'modality' => 'unico', 'receipt_number' => '001-000099'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_number');
    }

    public function test_el_recibo_se_compara_sin_espacios_ni_mayusculas(): void
    {
        $this->pay(['amount' => 500, 'modality' => 'fraccionado', 'receipt_number' => 'rc-000123'])->assertCreated()->assertJsonPath('receipt_number', 'RC-000123');

        $this->pay(['amount' => 150, 'receipt_number' => ' RC-000 123 '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_number');
    }

    public function test_el_recibo_solo_admite_letras_numeros_y_guiones(): void
    {
        $this->pay(['amount' => 600, 'modality' => 'unico', 'receipt_number' => '001/45#'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt_number' => 'El N° de recibo solo puede tener letras, números y guiones (3 a 30 caracteres).']);
    }

    public function test_corregir_el_recibo_tambien_exige_que_sea_unico(): void
    {
        $first = $this->pay(['amount' => 500, 'modality' => 'fraccionado', 'receipt_number' => 'RC-A'])->json('id');
        $this->pay(['amount' => 150, 'receipt_number' => 'RC-B'])->assertCreated();

        $this->actingAs($this->student)->putJson("/api/payments/{$first}", ['receipt_number' => 'RC-B'])
            ->assertUnprocessable()->assertJsonValidationErrors('receipt_number');

        // Repetir el suyo propio no es un duplicado.
        $this->actingAs($this->student)->putJson("/api/payments/{$first}", ['receipt_number' => 'RC-A'])->assertOk();
    }

    // ---------- Configuración del ciclo ----------

    public function test_sin_modalidades_configuradas_no_se_aceptan_pagos(): void
    {
        $this->enrollment->semester->update(['payment_plans' => null]);

        $this->pay(['amount' => 600, 'modality' => 'unico'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enrollment_id' => 'Los montos de pago de este ciclo aún no están configurados. Comunícate con la administración.']);
    }

    public function test_sin_modalidades_del_ciclo_usa_el_costo_global_como_respaldo(): void
    {
        $this->enrollment->semester->update(['payment_plans' => null]);
        SystemSetting::setValue(PaymentService::COST_SETTING, '300', 'decimal');
        SystemSetting::setValue(PaymentService::INSTALLMENTS_SETTING, '2', 'integer');

        $this->pay(['amount' => 150, 'modality' => 'unico'])
            ->assertCreated()
            ->assertJsonPath('installment_plan', [['number' => 1, 'amount' => 150], ['number' => 2, 'amount' => 150]]);
    }

    public function test_cada_ciclo_tiene_sus_propios_montos(): void
    {
        $this->enrollment->semester->update(['payment_plans' => [
            'unico' => ['label' => 'Pago único', 'installments' => [550]],
            'tres_cuotas' => ['label' => 'Tres cuotas', 'installments' => [300, 200, 100]],
        ]]);

        $this->pay(['amount' => 300, 'modality' => 'tres_cuotas'])->assertCreated()->assertJsonPath('total_installments', 3);
        $this->pay(['amount' => 200])->assertCreated();
        $this->pay(['amount' => 100])->assertCreated()->assertJsonPath('installment_number', 3);
    }
}
