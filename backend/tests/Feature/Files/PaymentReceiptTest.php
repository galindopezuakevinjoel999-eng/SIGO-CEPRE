<?php

namespace Tests\Feature\Files;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Comprobantes de pago en el disco privado (RF18). */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        // Respaldo global: S/ 500 en 5 cuotas de S/ 100 (permite varios pagos por prueba).
        SystemSetting::setValue(PaymentService::COST_SETTING, 500, 'decimal');
        SystemSetting::setValue(PaymentService::INSTALLMENTS_SETTING, 5, 'integer');

        $this->student = User::factory()->student()->withCompletedProfile()->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'student')->create();
    }

    private function pay(mixed $receipt)
    {
        return $this->actingAs($this->student)->postJson('/api/payments', [
            'enrollment_id' => $this->enrollment->id,
            'amount' => 100,
            'modality' => 'unico',
            'receipt_number' => $this->receiptNumber(),
            'receipt' => $receipt,
        ]);
    }

    private function registeredPayment(string $name = 'voucher.pdf'): Payment
    {
        return Payment::find($this->pay($this->fakePdf($name))->assertCreated()->json('id'));
    }

    // ---------- Subida ----------

    public function test_el_comprobante_se_guarda_en_el_disco_privado(): void
    {
        $payment = $this->registeredPayment('voucher-yape.pdf');

        $this->assertSame('voucher-yape.pdf', $payment->receipt_name);
        $this->assertStringStartsWith("payments/{$this->enrollment->id}/", $payment->receipt_path);
        $this->privateDisk()->assertExists($payment->receipt_path);
    }

    public function test_acepta_imagenes_jpg_y_png(): void
    {
        $this->pay(UploadedFile::fake()->create('captura.png', 200, 'image/png'))->assertCreated();
        $this->pay(UploadedFile::fake()->create('foto.jpg', 200, 'image/jpeg'))->assertCreated();
    }

    public function test_el_comprobante_es_obligatorio(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/payments', ['enrollment_id' => $this->enrollment->id, 'amount' => 100, 'modality' => 'unico', 'receipt_number' => $this->receiptNumber()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt' => 'Adjunta el comprobante de pago.']);
    }

    public function test_solo_acepta_pdf_jpg_y_png(): void
    {
        $this->pay(UploadedFile::fake()->create('voucher.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt' => 'El comprobante debe ser PDF, JPG o PNG.']);
    }

    public function test_pesa_maximo_5_mb(): void
    {
        $this->pay($this->fakePdf('voucher.pdf', 5121))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt' => 'El comprobante puede pesar como máximo 5 MB.']);

        $this->assertEmpty($this->privateDisk()->allFiles());
    }

    public function test_receipt_url_ya_no_se_acepta(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/payments', [
                'enrollment_id' => $this->enrollment->id,
                'amount' => 100,
                'receipt_url' => 'https://ejemplo.com/voucher.jpg',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt');
    }

    // ---------- Reemplazo y eliminación ----------

    public function test_corregir_con_un_comprobante_nuevo_borra_el_anterior(): void
    {
        $payment = $this->registeredPayment('voucher-borroso.pdf');
        $old = $payment->receipt_path;

        $this->actingAs($this->student)
            ->putJson("/api/payments/{$payment->id}", ['receipt' => $this->fakePdf('voucher-nitido.pdf')])
            ->assertOk();

        $payment->refresh();
        $this->privateDisk()->assertMissing($old);
        $this->privateDisk()->assertExists($payment->receipt_path);
        $this->assertSame('voucher-nitido.pdf', $payment->receipt_name);
    }

    public function test_corregir_sin_comprobante_conserva_el_actual(): void
    {
        $payment = $this->registeredPayment();
        $path = $payment->receipt_path;

        $this->actingAs($this->student)->putJson("/api/payments/{$payment->id}", ['payment_method' => 'caja'])->assertOk();

        $this->assertSame($path, $payment->fresh()->receipt_path);
        $this->privateDisk()->assertExists($path);
    }

    public function test_eliminar_el_pago_borra_el_comprobante(): void
    {
        $payment = $this->registeredPayment();

        $this->actingAs($this->student)->deleteJson("/api/payments/{$payment->id}")->assertOk();

        $this->privateDisk()->assertMissing($payment->receipt_path);
    }

    // ---------- Descarga ----------

    public function test_el_estudiante_descarga_su_comprobante(): void
    {
        $payment = $this->registeredPayment('voucher-cuota-1.pdf');

        $this->actingAs($this->student)
            ->get("/api/payments/{$payment->id}/receipt")
            ->assertOk()
            ->assertDownload('voucher-cuota-1.pdf');
    }

    public function test_otro_estudiante_no_lo_descarga(): void
    {
        $payment = $this->registeredPayment();

        $this->actingAs(User::factory()->student()->create())
            ->getJson("/api/payments/{$payment->id}/receipt")
            ->assertForbidden();
    }

    public function test_admin_y_coordinador_descargan_cualquier_comprobante(): void
    {
        $payment = $this->registeredPayment('voucher.pdf');

        foreach ([User::factory()->admin()->create(), User::factory()->coordinator()->create()] as $staff) {
            $this->actingAs($staff)->get("/api/payments/{$payment->id}/receipt")->assertOk()->assertDownload('voucher.pdf');
        }
    }

    public function test_el_docente_no_descarga_comprobantes(): void
    {
        $payment = $this->registeredPayment();

        $this->actingAs(User::factory()->teacher()->create())
            ->getJson("/api/payments/{$payment->id}/receipt")
            ->assertForbidden();
    }

    public function test_un_pago_sin_archivo_en_el_disco_responde_404(): void
    {
        // Pagos creados antes de esta fase: receipt_path vacío o con una URL antigua.
        foreach ([null, 'https://ejemplo.com/voucher.jpg'] as $path) {
            $payment = Payment::factory()->for($this->student, 'student')->for($this->enrollment)->create(['receipt_path' => $path]);

            $this->actingAs($this->student)->getJson("/api/payments/{$payment->id}/receipt")->assertNotFound();
        }
    }
}
