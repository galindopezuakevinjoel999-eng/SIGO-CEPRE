<?php

namespace Tests\Feature\Content;

use App\Models\Career;
use App\Models\Content\Announcement;
use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use App\Models\Content\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

/** /api/admin/content/* — gestión del contenido público (solo admin). */
class AdminContentTest extends TestCase
{
    use RefreshDatabase;
    use RefreshMongoDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->admin = User::factory()->admin()->create(['name' => 'Administrador CEPRE']);
    }

    private function cover(string $name = 'portada.jpg', int $kb = 300): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'image/jpeg');
    }

    // ---------- Permisos (ContentPolicy) ----------

    public function test_solo_el_admin_gestiona_el_contenido(): void
    {
        $faq = Faq::create(['question' => '¿?', 'answer' => 'R', 'category' => 'general']);

        foreach ([User::factory()->coordinator()->create(), User::factory()->teacher()->create(), User::factory()->student()->create()] as $user) {
            $this->actingAs($user)->getJson('/api/admin/content/news')->assertForbidden();
            $this->actingAs($user)->postJson('/api/admin/content/faqs', ['question' => '¿Q?', 'answer' => 'R', 'category' => 'general'])
                ->assertForbidden()
                ->assertJsonPath('message', 'Solo administración puede gestionar el contenido público.');
            $this->actingAs($user)->deleteJson("/api/admin/content/faqs/{$faq->id}")->assertForbidden();
        }

        $this->assertSame(1, Faq::count());
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->getJson('/api/admin/content/news')->assertUnauthorized();
    }

    // ---------- Noticias ----------

    public function test_crear_noticia_genera_slug_autor_y_fecha_de_publicacion(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/content/news', [
            'title' => 'Resultados del Primer Examen',
            'summary' => 'Ya están disponibles.',
            'content' => 'Contenido de la noticia',
            'tags' => ['examen', 'resultados'],
            'status' => 'published',
        ])
            ->assertCreated()
            ->assertJsonPath('slug', 'resultados-del-primer-examen')
            ->assertJsonPath('author.name', 'Administrador CEPRE')
            ->assertJsonPath('tags', ['examen', 'resultados'])
            ->json('id');

        $this->assertNotNull(News::find($id)->published_at);
        $this->getJson('/api/public/news/resultados-del-primer-examen')->assertOk();
    }

    public function test_el_slug_es_unico(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/content/news', ['title' => 'Inicio de clases', 'content' => 'A'])->assertJsonPath('slug', 'inicio-de-clases');
        $this->actingAs($this->admin)->postJson('/api/admin/content/news', ['title' => 'Inicio de clases', 'content' => 'B'])->assertJsonPath('slug', 'inicio-de-clases-2');

        $this->actingAs($this->admin)
            ->postJson('/api/admin/content/news', ['title' => 'Otra', 'content' => 'C', 'slug' => 'inicio-de-clases'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug' => 'Ya existe una noticia con ese slug.']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/content/news', ['title' => 'Otra', 'content' => 'C', 'slug' => 'Con Espacios'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_editar_el_titulo_no_cambia_el_slug(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/content/news', ['title' => 'Título original', 'content' => 'A'])->json('id');

        $this->actingAs($this->admin)
            ->putJson("/api/admin/content/news/{$id}", ['title' => 'Título corregido'])
            ->assertOk()
            ->assertJsonPath('title', 'Título corregido')
            ->assertJsonPath('slug', 'titulo-original');
    }

    public function test_la_portada_va_al_disco_publico(): void
    {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/admin/content/news', ['title' => 'Con portada', 'content' => 'A', 'cover_image' => $this->cover()])
            ->assertCreated()
            ->json('id');

        $news = News::find($id);
        Storage::disk('public')->assertExists($news->cover_image);
        $this->assertStringStartsWith('news/', $news->cover_image);
        $this->assertSame(Storage::disk('public')->url($news->cover_image), $news->cover_image_url);
        $this->privateDisk()->assertMissing($news->cover_image);
    }

    public function test_la_portada_debe_ser_imagen_de_hasta_2_mb(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/content/news', ['title' => 'X', 'content' => 'A', 'cover_image' => UploadedFile::fake()->create('portada.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cover_image' => 'La portada debe ser JPG, PNG o WEBP.']);

        $this->actingAs($this->admin)
            ->postJson('/api/admin/content/news', ['title' => 'X', 'content' => 'A', 'cover_image' => $this->cover('grande.jpg', 2049)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cover_image' => 'La portada puede pesar como máximo 2 MB.']);
    }

    public function test_reemplazar_o_quitar_la_portada_borra_la_anterior(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/content/news', ['title' => 'N', 'content' => 'A', 'cover_image' => $this->cover('v1.jpg')])->json('id');
        $first = News::find($id)->cover_image;

        $this->actingAs($this->admin)->putJson("/api/admin/content/news/{$id}", ['cover_image' => $this->cover('v2.jpg')])->assertOk();
        $second = News::find($id)->cover_image;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->actingAs($this->admin)->putJson("/api/admin/content/news/{$id}", ['remove_cover_image' => true])->assertOk();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull(News::find($id)->cover_image);
    }

    public function test_eliminar_la_noticia_borra_su_portada(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/content/news', ['title' => 'N', 'content' => 'A', 'cover_image' => $this->cover()])->json('id');
        $path = News::find($id)->cover_image;

        $this->actingAs($this->admin)->deleteJson("/api/admin/content/news/{$id}")->assertOk();

        $this->assertNull(News::find($id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_el_admin_ve_tambien_los_borradores(): void
    {
        News::create(['title' => 'Borrador', 'slug' => 'borrador', 'content' => 'A', 'status' => 'draft']);

        $this->actingAs($this->admin)->getJson('/api/admin/content/news')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/admin/content/news?status=published')->assertJsonCount(0, 'data');
    }

    public function test_un_id_inexistente_responde_404(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/content/news/000000000000000000000000')->assertNotFound();
        $this->actingAs($this->admin)->putJson('/api/admin/content/faqs/no-es-un-id', ['question' => '¿?'])->assertNotFound();
    }

    // ---------- Comunicados ----------

    public function test_crear_comunicado_para_grupos(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [
            'title' => 'Simulacro de examen',
            'content' => 'Para los grupos A y B',
            'type' => 'aviso',
            'priority' => 'alta',
            'audience' => ['scope' => 'grupo', 'groups' => ['a', 'B']],
            'valid_from' => '2026-11-01',
            'valid_until' => '2026-11-30',
            'status' => 'published',
        ])
            ->assertCreated()
            ->assertJsonPath('audience', ['scope' => 'grupo', 'groups' => ['A', 'B'], 'career_codes' => []])
            ->assertJsonPath('author.name', 'Administrador CEPRE');
    }

    public function test_la_audiencia_se_valida(): void
    {
        Career::factory()->create(['code' => 'SIS']);
        $base = ['title' => 'C', 'content' => 'C'];

        $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [...$base, 'audience' => ['scope' => 'grupo']])
            ->assertUnprocessable()->assertJsonValidationErrors(['audience.groups' => 'Indica a qué grupos va dirigido.']);

        $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [...$base, 'audience' => ['scope' => 'grupo', 'groups' => ['D']]])
            ->assertUnprocessable()->assertJsonValidationErrors('audience.groups.0');

        $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [...$base, 'audience' => ['scope' => 'carrera', 'career_codes' => ['XYZ']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['audience.career_codes.0' => 'La carrera XYZ no existe.']);

        $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [...$base, 'audience' => ['scope' => 'carrera', 'career_codes' => ['SIS']]])
            ->assertCreated();
    }

    public function test_la_vigencia_debe_ser_coherente(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [
            'title' => 'C', 'content' => 'C', 'valid_from' => '2026-11-30', 'valid_until' => '2026-11-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('valid_until');

        // Solo con fecha de fin (sin inicio) es válido.
        $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [
            'title' => 'C', 'content' => 'C', 'valid_until' => '2026-12-31',
        ])->assertCreated();
    }

    public function test_los_adjuntos_van_al_disco_publico_y_se_reemplazan(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/content/announcements', [
            'title' => 'Cronograma', 'content' => 'Adjunto',
            'attachments' => [$this->fakePdf('cronograma-v1.pdf')],
        ])->assertCreated()->assertJsonPath('attachments.0.name', 'cronograma-v1.pdf')->json('id');

        $old = Announcement::find($id)->attachments[0]['path'];
        Storage::disk('public')->assertExists($old);

        $this->actingAs($this->admin)->putJson("/api/admin/content/announcements/{$id}", [
            'attachments' => [$this->fakePdf('cronograma-v2.pdf')],
        ])->assertOk()->assertJsonCount(1, 'attachments');

        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists(Announcement::find($id)->attachments[0]['path']);

        $this->actingAs($this->admin)->deleteJson("/api/admin/content/announcements/{$id}")->assertOk();
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    // ---------- FAQ y bloques ----------

    public function test_crud_de_faqs(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/content/faqs', [
            'question' => '¿Hay becas?', 'answer' => 'Consulta en secretaría.', 'category' => 'pagos', 'keywords' => ['beca'], 'order' => 3,
        ])->assertCreated()->assertJsonPath('active', true)->json('id');

        $this->actingAs($this->admin)->putJson("/api/admin/content/faqs/{$id}", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->getJson('/api/public/faqs')->assertJsonCount(0);

        $this->actingAs($this->admin)->postJson('/api/admin/content/faqs', ['question' => '¿?', 'answer' => 'R', 'category' => 'deportes'])
            ->assertUnprocessable()->assertJsonValidationErrors('category');

        $this->actingAs($this->admin)->deleteJson("/api/admin/content/faqs/{$id}")->assertOk();
        $this->assertSame(0, Faq::count());
    }

    public function test_crud_de_bloques_del_inicio(): void
    {
        $id = $this->actingAs($this->admin)->postJson('/api/admin/content/home-blocks', [
            'type' => 'banner', 'order' => 1, 'data' => ['title' => 'Ciclo 2026-II', 'cta' => ['label' => 'Matricúlate', 'href' => '/login']],
        ])->assertCreated()->assertJsonPath('data.cta.href', '/login')->json('id');

        $this->actingAs($this->admin)->putJson("/api/admin/content/home-blocks/{$id}", ['data' => ['title' => 'Ciclo 2027-I']])
            ->assertOk()->assertJsonPath('data.title', 'Ciclo 2027-I');

        $this->actingAs($this->admin)->postJson('/api/admin/content/home-blocks', ['type' => 'popup', 'data' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->actingAs($this->admin)->deleteJson("/api/admin/content/home-blocks/{$id}")->assertOk();
        $this->assertSame(0, HomeBlock::count());
    }
}
