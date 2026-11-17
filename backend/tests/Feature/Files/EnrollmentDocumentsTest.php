<?php

namespace Tests\Feature\Files;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Documentos del paso 3 por tipo, en el disco privado:
 * recibo_pago (JPG/PNG/PDF), foto_postulante (JPG/PNG), dni (PDF) y
 * certificado_estudios (PDF, opcional: si falta, queda pendiente de regularizar).
 */
class EnrollmentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->withCompletedProfile()->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'student')->create(['current_step' => 2]);
    }

    private function uploadStep3(array $documents)
    {
        return $this->actingAs($this->student)
            ->putJson("/api/enrollments/{$this->enrollment->id}", ['step' => 3, 'documents' => $documents]);
    }

    private function document(string $type): ?array
    {
        return $this->enrollment->fresh()->documentOfType($type);
    }

    // ---------- Subida por tipo ----------

    public function test_los_documentos_se_guardan_por_tipo_en_el_disco_privado(): void
    {
        $this->uploadStep3([
            ...$this->requiredDocuments(),
            'certificado_estudios' => $this->fakePdf('certificado.pdf'),
        ])->assertOk()->assertJsonCount(4, 'documents');

        foreach (['recibo_pago' => 'recibo.pdf', 'foto_postulante' => 'foto.jpg', 'dni' => 'dni.pdf', 'certificado_estudios' => 'certificado.pdf'] as $type => $name) {
            $document = $this->document($type);
            $this->assertSame($name, $document['name']);
            $this->assertStringStartsWith("enrollments/{$this->enrollment->id}/", $document['path']);
            $this->privateDisk()->assertExists($document['path']);
        }
    }

    public function test_el_recibo_acepta_imagen_o_pdf(): void
    {
        $this->uploadStep3($this->requiredDocuments(['recibo_pago' => $this->fakeImage('recibo.png')]))->assertOk();
        $this->assertSame('recibo.png', $this->document('recibo_pago')['name']);
    }

    public function test_los_tres_obligatorios_se_exigen_en_el_paso_3(): void
    {
        $this->uploadStep3([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'documents.recibo_pago' => 'Adjunta: Recibo de pago.',
                'documents.foto_postulante' => 'Adjunta: Foto del postulante.',
                'documents.dni' => 'Adjunta: DNI.',
            ])
            ->assertJsonMissingValidationErrors('documents.certificado_estudios');
    }

    public function test_cada_tipo_tiene_sus_formatos(): void
    {
        $cases = [
            'foto_postulante' => [$this->fakePdf('foto.pdf'), 'Foto del postulante: el formato debe ser JPG/PNG.'],
            'dni' => [$this->fakeImage('dni.jpg'), 'DNI: el formato debe ser PDF.'],
            'certificado_estudios' => [$this->fakeImage('certificado.png'), 'Certificado de estudios: el formato debe ser PDF.'],
            'recibo_pago' => [UploadedFile::fake()->create('recibo.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'Recibo de pago: el formato debe ser JPG/PNG/PDF.'],
        ];

        foreach ($cases as $type => [$file, $message]) {
            $this->uploadStep3($this->requiredDocuments([$type => $file]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(["documents.{$type}" => $message]);
        }

        $this->assertEmpty($this->privateDisk()->allFiles());
    }

    public function test_cada_documento_pesa_maximo_5_mb(): void
    {
        $this->uploadStep3($this->requiredDocuments(['dni' => $this->fakePdf('dni.pdf', 5121)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.dni' => 'DNI: puede pesar como máximo 5 MB.']);
    }

    public function test_un_tipo_desconocido_es_rechazado(): void
    {
        $this->uploadStep3([...$this->requiredDocuments(), 'partida_nacimiento' => $this->fakePdf('partida.pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');
    }

    public function test_texto_en_lugar_de_archivo_es_rechazado(): void
    {
        $this->uploadStep3($this->requiredDocuments(['dni' => 'https://ejemplo.com/dni.pdf']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents.dni');
    }

    // ---------- Reemplazo ----------

    public function test_subir_un_tipo_reemplaza_solo_ese_y_borra_el_anterior(): void
    {
        $this->uploadStep3($this->requiredDocuments())->assertOk();
        $oldDni = $this->document('dni')['path'];
        $photo = $this->document('foto_postulante')['path'];

        $this->uploadStep3(['dni' => $this->fakePdf('dni-nitido.pdf')])->assertOk();

        $this->privateDisk()->assertMissing($oldDni);
        $this->assertSame('dni-nitido.pdf', $this->document('dni')['name']);
        $this->assertSame($photo, $this->document('foto_postulante')['path'], 'La foto no cambia');
        $this->privateDisk()->assertExists($photo);
        $this->assertCount(3, $this->enrollment->fresh()->documents);
    }

    public function test_eliminar_la_matricula_borra_sus_documentos(): void
    {
        $this->uploadStep3($this->requiredDocuments())->assertOk();
        $paths = $this->enrollment->fresh()->documentPaths();

        $this->actingAs($this->student)->deleteJson("/api/enrollments/{$this->enrollment->id}")->assertOk();

        foreach ($paths as $path) {
            $this->privateDisk()->assertMissing($path);
        }
    }

    // ---------- Certificado de estudios ----------

    public function test_sin_certificado_queda_pendiente_de_regularizar(): void
    {
        $this->uploadStep3($this->requiredDocuments())->assertOk()->assertJsonPath('certificate_status', Enrollment::CERTIFICATE_PENDING);
    }

    public function test_con_certificado_queda_subido(): void
    {
        $this->uploadStep3([...$this->requiredDocuments(), 'certificado_estudios' => $this->fakePdf('certificado.pdf')])
            ->assertOk()
            ->assertJsonPath('certificate_status', Enrollment::CERTIFICATE_UPLOADED);
    }

    public function test_subirlo_despues_regulariza_el_certificado(): void
    {
        $this->uploadStep3($this->requiredDocuments())->assertOk();

        $this->uploadStep3(['certificado_estudios' => $this->fakePdf('certificado.pdf')])
            ->assertOk()
            ->assertJsonPath('certificate_status', Enrollment::CERTIFICATE_UPLOADED);
    }

    public function test_antes_del_paso_3_no_hay_estado_de_certificado(): void
    {
        $this->actingAs($this->student)->putJson("/api/enrollments/{$this->enrollment->id}", ['step' => 2])
            ->assertOk()
            ->assertJsonPath('certificate_status', null);
    }

    public function test_la_administracion_marca_el_certificado_como_entregado(): void
    {
        $this->uploadStep3($this->requiredDocuments())->assertOk();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson("/api/enrollments/{$this->enrollment->id}/certificate")
            ->assertOk()
            ->assertJsonPath('certificate_status', Enrollment::CERTIFICATE_DELIVERED)
            ->assertJsonPath('certificate_delivered_by', $admin->id);

        $log = AuditLog::sole();
        $this->assertSame(AuditLogger::CERTIFICATE_DELIVERED, $log->action);
        $this->assertSame(['enrollment_id' => $this->enrollment->id, 'previous_status' => 'pending'], $log->metadata);

        // Entregado en físico: volver al paso 3 no lo devuelve a pendiente.
        $this->uploadStep3(['dni' => $this->fakePdf('dni2.pdf')])->assertOk()->assertJsonPath('certificate_status', Enrollment::CERTIFICATE_DELIVERED);

        $this->actingAs($admin)->putJson("/api/enrollments/{$this->enrollment->id}/certificate")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El certificado ya figura como entregado.');
    }

    public function test_el_estudiante_no_marca_su_certificado(): void
    {
        $this->actingAs($this->student)->putJson("/api/enrollments/{$this->enrollment->id}/certificate")->assertForbidden();
        $this->actingAs(User::factory()->teacher()->create())->putJson("/api/enrollments/{$this->enrollment->id}/certificate")->assertForbidden();
    }

    // ---------- Descarga ----------

    private function documentUrl(): string
    {
        $this->uploadStep3($this->requiredDocuments())->assertOk();

        return "/api/enrollments/{$this->enrollment->id}/documents/{$this->document('dni')['id']}";
    }

    public function test_el_estudiante_descarga_su_documento(): void
    {
        $this->actingAs($this->student)->get($this->documentUrl())->assertOk()->assertDownload('dni.pdf');
    }

    public function test_otro_estudiante_no_puede_descargarlo(): void
    {
        $url = $this->documentUrl();

        $this->actingAs(User::factory()->student()->withCompletedProfile()->create())->getJson($url)->assertForbidden();
    }

    public function test_admin_y_coordinador_descargan_cualquier_documento(): void
    {
        $url = $this->documentUrl();

        $this->actingAs(User::factory()->admin()->create())->get($url)->assertOk()->assertDownload('dni.pdf');
        $this->actingAs(User::factory()->coordinator()->create())->get($url)->assertOk()->assertDownload('dni.pdf');
    }

    public function test_el_docente_no_descarga_documentos_de_matricula(): void
    {
        $url = $this->documentUrl();

        $this->actingAs(User::factory()->teacher()->create())->getJson($url)->assertForbidden();
    }

    public function test_sin_sesion_no_se_descarga(): void
    {
        $url = $this->documentUrl();
        $this->app['auth']->forgetGuards();

        $this->getJson($url)->assertUnauthorized();
    }

    public function test_un_documento_inexistente_responde_404(): void
    {
        $this->actingAs($this->student)
            ->getJson("/api/enrollments/{$this->enrollment->id}/documents/no-existe")
            ->assertNotFound();
    }

    public function test_los_archivos_no_tienen_url_publica(): void
    {
        $this->documentUrl();

        $this->get('/storage/'.$this->document('dni')['path'])->assertNotFound();
        $this->assertArrayNotHasKey('url', config('filesystems.disks.private'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }

    public function test_tambien_se_pueden_subir_al_crear_la_matricula(): void
    {
        $this->openEnrollmentCycle();
        $other = User::factory()->student()->withCompletedProfile()->create();

        $id = $this->actingAs($other)->postJson('/api/enrollments', [
            'step' => 3,
            'career_id' => Career::factory()->create()->id,
            'documents' => $this->requiredDocuments(),
        ])->assertCreated()->json('id');

        $this->privateDisk()->assertExists(Enrollment::find($id)->documentOfType('dni')['path']);
    }
}
