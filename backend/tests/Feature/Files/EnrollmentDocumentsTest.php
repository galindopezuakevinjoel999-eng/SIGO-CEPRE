<?php

namespace Tests\Feature\Files;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Documentos de matrícula (paso 3) en el disco privado. */
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

    private function uploadStep3(array $documents, ?Enrollment $enrollment = null)
    {
        $enrollment ??= $this->enrollment;

        return $this->actingAs($this->student)
            ->putJson("/api/enrollments/{$enrollment->id}", ['step' => 3, 'documents' => $documents]);
    }

    private function uploadedDocuments(): array
    {
        return $this->enrollment->fresh()->documents;
    }

    // ---------- Subida ----------

    public function test_los_documentos_se_guardan_en_el_disco_privado(): void
    {
        $this->uploadStep3([
            $this->fakePdf('dni.pdf'),
            UploadedFile::fake()->create('foto.jpg', 200, 'image/jpeg'),
            UploadedFile::fake()->create('certificado.png', 200, 'image/png'),
        ])->assertOk()->assertJsonCount(3, 'documents');

        $documents = $this->uploadedDocuments();
        $this->assertSame(['dni.pdf', 'foto.jpg', 'certificado.png'], array_column($documents, 'name'));

        foreach ($documents as $document) {
            $this->privateDisk()->assertExists($document['path']);
            $this->assertStringStartsWith("enrollments/{$this->enrollment->id}/", $document['path']);
            $this->assertNotEmpty($document['id']);
        }
    }

    public function test_tambien_se_pueden_subir_al_crear_la_matricula(): void
    {
        $other = User::factory()->student()->withCompletedProfile()->create();

        $id = $this->actingAs($other)->postJson('/api/enrollments', [
            'step' => 3,
            'career_id' => Career::factory()->create()->id,
            'documents' => [$this->fakePdf('dni.pdf')],
        ])->assertCreated()->json('id');

        $this->privateDisk()->assertExists(Enrollment::find($id)->documents[0]['path']);
    }

    public function test_solo_acepta_pdf_jpg_y_png(): void
    {
        foreach ([
            UploadedFile::fake()->create('dni.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            UploadedFile::fake()->create('script.php', 1, 'application/x-php'),
            UploadedFile::fake()->create('foto.gif', 10, 'image/gif'),
            // Extensión engañosa: dice .pdf pero es un ejecutable.
            UploadedFile::fake()->create('dni.pdf', 10, 'application/x-msdownload'),
        ] as $file) {
            $this->uploadStep3([$file])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['documents.0']);
        }

        $this->assertEmpty($this->privateDisk()->allFiles());
    }

    public function test_cada_documento_pesa_maximo_5_mb(): void
    {
        $this->uploadStep3([$this->fakePdf('ok.pdf', 5120), $this->fakePdf('grande.pdf', 5121)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents.1' => 'Cada documento puede pesar como máximo 5 MB.'])
            ->assertJsonMissingValidationErrors('documents.0');
    }

    public function test_texto_en_lugar_de_archivo_es_rechazado(): void
    {
        $this->uploadStep3(['https://ejemplo.com/dni.pdf'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents.0');
    }

    public function test_el_paso_3_exige_documentos(): void
    {
        $this->uploadStep3([])->assertUnprocessable()->assertJsonValidationErrors('documents');
    }

    // ---------- Reemplazo y eliminación ----------

    public function test_subir_de_nuevo_reemplaza_y_borra_los_anteriores(): void
    {
        $this->uploadStep3([$this->fakePdf('dni-borroso.pdf')])->assertOk();
        $old = $this->uploadedDocuments()[0]['path'];

        $this->uploadStep3([$this->fakePdf('dni-nitido.pdf')])->assertOk();

        $this->privateDisk()->assertMissing($old);
        $documents = $this->uploadedDocuments();
        $this->assertCount(1, $documents);
        $this->assertSame('dni-nitido.pdf', $documents[0]['name']);
        $this->privateDisk()->assertExists($documents[0]['path']);
    }

    public function test_avanzar_de_paso_sin_documentos_nuevos_los_conserva(): void
    {
        $this->uploadStep3([$this->fakePdf('dni.pdf')])->assertOk();
        $path = $this->uploadedDocuments()[0]['path'];

        $this->actingAs($this->student)->putJson("/api/enrollments/{$this->enrollment->id}", ['step' => 4])->assertOk();

        $this->privateDisk()->assertExists($path);
        $this->assertSame($path, $this->uploadedDocuments()[0]['path']);
    }

    public function test_eliminar_la_matricula_borra_sus_documentos(): void
    {
        $this->uploadStep3([$this->fakePdf('dni.pdf'), $this->fakePdf('certificado.pdf')])->assertOk();
        $paths = array_column($this->uploadedDocuments(), 'path');

        $this->actingAs($this->student)->deleteJson("/api/enrollments/{$this->enrollment->id}")->assertOk();

        foreach ($paths as $path) {
            $this->privateDisk()->assertMissing($path);
        }
    }

    // ---------- Descarga ----------

    private function documentUrl(): string
    {
        $this->uploadStep3([$this->fakePdf('dni.pdf')])->assertOk();

        return "/api/enrollments/{$this->enrollment->id}/documents/{$this->uploadedDocuments()[0]['id']}";
    }

    public function test_el_estudiante_descarga_su_documento(): void
    {
        $url = $this->documentUrl();

        $this->actingAs($this->student)->get($url)->assertOk()->assertDownload('dni.pdf');
    }

    public function test_otro_estudiante_no_puede_descargarlo(): void
    {
        $url = $this->documentUrl();
        $other = User::factory()->student()->withCompletedProfile()->create();

        $this->actingAs($other)->getJson($url)->assertForbidden();
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
        $url = $this->documentUrl();
        $path = $this->uploadedDocuments()[0]['path'];

        $this->get("/storage/{$path}")->assertNotFound();
        $this->assertArrayNotHasKey('url', config('filesystems.disks.private'));
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertNotNull($url);
    }
}
