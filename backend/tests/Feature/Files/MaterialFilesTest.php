<?php

namespace Tests\Feature\Files;

use App\Models\Enrollment;
use App\Models\Material;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Materiales de estudio en el disco privado (RF27–RF29). */
class MaterialFilesTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const PPTX = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    private User $teacher;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->teacher()->create();
        $this->semester = Semester::factory()->active()->create();
    }

    private function upload(UploadedFile $file, ?User $user = null, array $extra = [])
    {
        return $this->actingAs($user ?? $this->teacher)
            ->postJson('/api/materials', ['title' => 'Separata de Álgebra', 'file' => $file, ...$extra]);
    }

    /** Material publicado con su archivo real en el disco (falso). */
    private function publishedMaterial(): Material
    {
        $id = $this->upload($this->fakePdf('algebra-semana-1.pdf'), User::factory()->admin()->create(), ['status' => 'published'])
            ->assertCreated()
            ->json('id');

        return Material::find($id);
    }

    private function enrolledStudent(?Semester $semester = null): User
    {
        $student = User::factory()->student()->withCompletedProfile()->create();
        Enrollment::factory()->approved()->for($student, 'student')->create(['semester_id' => ($semester ?? $this->semester)->id]);

        return $student;
    }

    // ---------- Subida ----------

    public function test_el_docente_sube_pdf_docx_y_pptx(): void
    {
        foreach ([
            ['separata.pdf', 'application/pdf', 'pdf'],
            ['practica.docx', self::DOCX, 'docx'],
            ['clase.pptx', self::PPTX, 'pptx'],
        ] as [$name, $mime, $type]) {
            $material = Material::find(
                $this->upload(UploadedFile::fake()->create($name, 500, $mime))
                    ->assertCreated()
                    ->assertJsonPath('type', $type)
                    ->assertJsonPath('file_name', $name)
                    ->assertJsonPath('status', 'draft') // el docente no publica
                    ->json('id')
            );

            $this->privateDisk()->assertExists($material->file_path);
            $this->assertStringStartsWith('materials/', $material->file_path);
        }
    }

    /**
     * Con archivos falsos el MIME es el que se declara; aquí se sube un DOCX
     * real para comprobar que fileinfo lo reconoce como docx (y no como zip).
     */
    public function test_acepta_un_docx_real_detectado_por_su_contenido(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Requiere la extensión zip de PHP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        // Estructura mínima de un .docx de Word. fileinfo necesita _rels/.rels para
        // reconocerlo; sin él lo ve como un .zip cualquiera (y se rechaza).
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
        $zip->close();

        $real = new UploadedFile($path, 'practica-calificada.docx', null, null, true);

        $this->upload($real)->assertCreated()->assertJsonPath('type', 'docx');
        @unlink($path);
    }

    public function test_un_zip_renombrado_a_docx_es_rechazado(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Requiere la extensión zip de PHP.');
        }

        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('virus.txt', 'no soy un documento');
        $zip->close();

        $this->upload(new UploadedFile($path, 'practica.docx', null, null, true))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
        @unlink($path);
    }

    public function test_el_archivo_es_obligatorio(): void
    {
        $this->actingAs($this->teacher)
            ->postJson('/api/materials', ['title' => 'Sin archivo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Adjunta el archivo del material.']);
    }

    public function test_solo_acepta_pdf_docx_y_pptx(): void
    {
        foreach ([
            UploadedFile::fake()->create('pizarra.jpg', 200, 'image/jpeg'),
            UploadedFile::fake()->create('notas.txt', 1, 'text/plain'),
            UploadedFile::fake()->create('programa.exe', 10, 'application/x-msdownload'),
        ] as $file) {
            $this->upload($file)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['file' => 'El material debe ser PDF, DOCX o PPTX.']);
        }

        $this->assertEmpty($this->privateDisk()->allFiles());
    }

    public function test_pesa_maximo_20_mb(): void
    {
        $this->upload($this->fakePdf('ok.pdf', 20480))->assertCreated();

        $this->upload($this->fakePdf('grande.pdf', 20481))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'El material puede pesar como máximo 20 MB.']);
    }

    public function test_estudiantes_y_coordinadores_no_suben_materiales(): void
    {
        foreach ([$this->enrolledStudent(), User::factory()->coordinator()->create()] as $user) {
            $this->upload($this->fakePdf(), $user)->assertForbidden();
        }

        $this->assertEmpty($this->privateDisk()->allFiles());
    }

    // ---------- Reemplazo y eliminación ----------

    public function test_reemplazar_el_archivo_borra_el_anterior(): void
    {
        $material = Material::find($this->upload($this->fakePdf('v1.pdf'))->assertCreated()->json('id'));
        $old = $material->file_path;

        $this->actingAs($this->teacher)
            ->putJson("/api/materials/{$material->id}", ['file' => UploadedFile::fake()->create('v2.pptx', 100, self::PPTX)])
            ->assertOk()
            ->assertJsonPath('file_name', 'v2.pptx')
            ->assertJsonPath('type', 'pptx');

        $this->privateDisk()->assertMissing($old);
        $this->privateDisk()->assertExists($material->fresh()->file_path);
    }

    public function test_editar_solo_el_titulo_conserva_el_archivo(): void
    {
        $material = Material::find($this->upload($this->fakePdf())->assertCreated()->json('id'));

        $this->actingAs($this->teacher)->putJson("/api/materials/{$material->id}", ['title' => 'Nuevo título'])->assertOk();

        $this->privateDisk()->assertExists($material->file_path);
    }

    public function test_otro_docente_no_puede_reemplazar_el_archivo(): void
    {
        $material = Material::find($this->upload($this->fakePdf())->assertCreated()->json('id'));

        $this->actingAs(User::factory()->teacher()->create())
            ->putJson("/api/materials/{$material->id}", ['file' => $this->fakePdf('ajeno.pdf')])
            ->assertForbidden();

        $this->assertCount(1, $this->privateDisk()->allFiles());
    }

    public function test_el_docente_no_puede_publicar_su_material(): void
    {
        $material = Material::find($this->upload($this->fakePdf())->assertCreated()->json('id'));

        $this->actingAs($this->teacher)->putJson("/api/materials/{$material->id}", ['status' => 'published'])->assertOk();

        $this->assertSame('draft', $material->fresh()->status);
    }

    public function test_eliminar_el_material_borra_su_archivo(): void
    {
        $material = $this->publishedMaterial();

        $this->actingAs(User::factory()->admin()->create())->deleteJson("/api/materials/{$material->id}")->assertOk();

        $this->privateDisk()->assertMissing($material->file_path);
    }

    // ---------- Descarga ----------

    public function test_el_estudiante_matriculado_descarga_materiales_publicados(): void
    {
        $material = $this->publishedMaterial();

        $this->actingAs($this->enrolledStudent())
            ->get("/api/materials/{$material->id}/download")
            ->assertOk()
            ->assertDownload('algebra-semana-1.pdf');
    }

    public function test_sin_matricula_aprobada_no_descarga(): void
    {
        $material = $this->publishedMaterial();
        $pending = User::factory()->student()->withCompletedProfile()->create();
        Enrollment::factory()->for($pending, 'student')->create(['semester_id' => $this->semester->id]); // pendiente

        foreach ([User::factory()->student()->create(), $pending] as $student) {
            $this->actingAs($student)->getJson("/api/materials/{$material->id}/download")->assertForbidden();
        }
    }

    public function test_una_matricula_de_un_semestre_anterior_no_da_acceso(): void
    {
        $material = $this->publishedMaterial();
        $exStudent = $this->enrolledStudent(Semester::factory()->create());

        $this->actingAs($exStudent)->getJson("/api/materials/{$material->id}/download")->assertForbidden();
    }

    public function test_el_estudiante_no_ve_borradores(): void
    {
        $draft = Material::find($this->upload($this->fakePdf())->assertCreated()->json('id'));
        $student = $this->enrolledStudent();

        $this->actingAs($student)->getJson("/api/materials/{$draft->id}/download")->assertNotFound();
        $this->actingAs($student)->getJson('/api/materials')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_el_personal_descarga_cualquier_material(): void
    {
        $draft = Material::find($this->upload($this->fakePdf('borrador.pdf'))->assertCreated()->json('id'));

        foreach ([$this->teacher, User::factory()->teacher()->create(), User::factory()->admin()->create(), User::factory()->coordinator()->create()] as $staff) {
            $this->actingAs($staff)->get("/api/materials/{$draft->id}/download")->assertOk()->assertDownload('borrador.pdf');
        }
    }

    public function test_el_listado_exige_matricula_al_estudiante(): void
    {
        $this->publishedMaterial();

        $this->actingAs(User::factory()->student()->create())->getJson('/api/materials')->assertForbidden();
        $this->actingAs($this->enrolledStudent())->getJson('/api/materials')->assertOk()->assertJsonCount(1, 'data');
    }
}
