<?php

namespace Tests\Feature\Content;

use App\Models\Content\Announcement;
use App\Models\Content\Faq;
use App\Models\Content\HomeBlock;
use App\Models\Content\News;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

/** /api/public/* — sitio público sin sesión (MongoDB: sigo_cepre_test). */
class PublicContentTest extends TestCase
{
    use RefreshDatabase;
    use RefreshMongoDatabase;

    private function news(array $attributes = []): News
    {
        static $n = 0;
        $n++;

        return News::create([
            'title' => "Noticia {$n}",
            'slug' => "noticia-{$n}",
            'summary' => 'Resumen',
            'content' => 'Contenido completo',
            'tags' => ['admision'],
            'author' => ['id' => 1, 'name' => 'Administrador CEPRE'],
            'status' => 'published',
            'published_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    private function announcement(array $attributes = []): Announcement
    {
        return Announcement::create([
            'title' => 'Comunicado',
            'content' => 'Texto',
            'type' => 'comunicado',
            'priority' => 'media',
            'audience' => ['scope' => 'todos', 'groups' => [], 'career_codes' => []],
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addWeek(),
            'status' => 'published',
            ...$attributes,
        ]);
    }

    // ---------- Noticias ----------

    public function test_lista_solo_noticias_publicadas_y_ya_vigentes(): void
    {
        $visible = $this->news(['title' => 'Publicada']);
        $this->news(['status' => 'draft']);
        $this->news(['published_at' => now()->addDay()]); // programada

        $this->getJson('/api/public/news')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $visible->slug)
            ->assertJsonMissingPath('data.0.content')  // el listado no trae el cuerpo
            ->assertJsonMissingPath('data.0.author');
    }

    public function test_las_mas_recientes_primero_y_filtro_por_etiqueta(): void
    {
        $this->news(['title' => 'Antigua', 'published_at' => now()->subWeek(), 'tags' => ['becas']]);
        $this->news(['title' => 'Reciente', 'published_at' => now()->subHour()]);

        $this->getJson('/api/public/news')->assertJsonPath('data.0.title', 'Reciente');
        $this->getJson('/api/public/news?tag=becas')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Antigua');
    }

    public function test_detalle_por_slug(): void
    {
        $news = $this->news(['slug' => 'resultados-primer-examen', 'content' => 'Felicitaciones a los ingresantes']);

        $this->getJson('/api/public/news/resultados-primer-examen')
            ->assertOk()
            ->assertJsonPath('id', $news->id)
            ->assertJsonPath('content', 'Felicitaciones a los ingresantes')
            ->assertJsonPath('author_name', 'Administrador CEPRE')
            ->assertJsonMissingPath('author');
    }

    public function test_un_borrador_no_se_ve_por_slug(): void
    {
        $this->news(['slug' => 'borrador', 'status' => 'draft']);

        $this->getJson('/api/public/news/borrador')->assertNotFound();
        $this->getJson('/api/public/news/no-existe')->assertNotFound();
    }

    // ---------- Comunicados ----------

    public function test_solo_comunicados_publicados_y_vigentes(): void
    {
        $this->announcement(['title' => 'Vigente']);
        $this->announcement(['title' => 'Sin fechas', 'valid_from' => null, 'valid_until' => null]);
        $this->announcement(['title' => 'Vencido', 'valid_until' => now()->subMinute()]);
        $this->announcement(['title' => 'Futuro', 'valid_from' => now()->addDay()]);
        $this->announcement(['title' => 'Borrador', 'status' => 'draft']);

        $titles = $this->getJson('/api/public/announcements')->assertOk()->json('*.title');

        $this->assertEqualsCanonicalizing(['Vigente', 'Sin fechas'], $titles);
    }

    public function test_primero_los_de_prioridad_alta(): void
    {
        $this->announcement(['title' => 'Baja', 'priority' => 'baja']);
        $this->announcement(['title' => 'Alta', 'priority' => 'alta']);
        $this->announcement(['title' => 'Media', 'priority' => 'media']);

        $this->assertSame(['Alta', 'Media', 'Baja'], $this->getJson('/api/public/announcements')->json('*.title'));
    }

    public function test_filtro_por_grupo_y_carrera(): void
    {
        $this->announcement(['title' => 'Para todos']);
        $this->announcement(['title' => 'Grupo A', 'audience' => ['scope' => 'grupo', 'groups' => ['A'], 'career_codes' => []]]);
        $this->announcement(['title' => 'Grupo B', 'audience' => ['scope' => 'grupo', 'groups' => ['B'], 'career_codes' => []]]);
        $this->announcement(['title' => 'Psicología', 'audience' => ['scope' => 'carrera', 'groups' => [], 'career_codes' => ['PSI']]]);

        $this->assertEqualsCanonicalizing(
            ['Para todos', 'Grupo A'],
            $this->getJson('/api/public/announcements?group=a')->json('*.title'),
        );
        $this->assertEqualsCanonicalizing(
            ['Para todos', 'Grupo B', 'Psicología'],
            $this->getJson('/api/public/announcements?group=B&career=PSI')->json('*.title'),
        );
        $this->assertCount(4, $this->getJson('/api/public/announcements')->json());
    }

    public function test_no_expone_autor_ni_estado(): void
    {
        $this->announcement(['author' => ['id' => 1, 'name' => 'Admin']]);

        $this->getJson('/api/public/announcements')
            ->assertJsonMissingPath('0.author')
            ->assertJsonMissingPath('0.status');
    }

    // ---------- FAQ ----------

    public function test_faqs_activas_ordenadas_y_por_categoria(): void
    {
        Faq::create(['question' => '¿Pago 2?', 'answer' => 'R', 'category' => 'pagos', 'order' => 2]);
        Faq::create(['question' => '¿Pago 1?', 'answer' => 'R', 'category' => 'pagos', 'order' => 1]);
        Faq::create(['question' => '¿Inactiva?', 'answer' => 'R', 'category' => 'pagos', 'active' => false]);
        Faq::create(['question' => '¿Matrícula?', 'answer' => 'R', 'category' => 'matricula', 'order' => 1]);

        $this->assertSame(['¿Matrícula?', '¿Pago 1?', '¿Pago 2?'], $this->getJson('/api/public/faqs')->assertOk()->json('*.question'));
        $this->assertSame(['¿Pago 1?', '¿Pago 2?'], $this->getJson('/api/public/faqs?category=pagos')->json('*.question'));
        $this->getJson('/api/public/faqs?category=otra')->assertUnprocessable();
    }

    // ---------- Inicio ----------

    public function test_home_reune_bloques_noticias_y_comunicados(): void
    {
        HomeBlock::create(['type' => 'cta', 'order' => 2, 'data' => ['title' => 'CTA']]);
        HomeBlock::create(['type' => 'banner', 'order' => 1, 'data' => ['title' => 'Banner']]);
        HomeBlock::create(['type' => 'fechas', 'order' => 3, 'data' => [], 'active' => false]);
        foreach (range(1, 5) as $i) {
            $this->news(['published_at' => now()->subDays($i)]);
        }
        $this->announcement(['title' => 'Aviso vigente']);
        $this->announcement(['title' => 'Vencido', 'valid_until' => now()->subDay()]);

        $this->getJson('/api/public/home')
            ->assertOk()
            ->assertJsonPath('blocks.0.type', 'banner')
            ->assertJsonPath('blocks.1.type', 'cta')
            ->assertJsonCount(2, 'blocks')
            ->assertJsonCount(3, 'latest_news')
            ->assertJsonCount(1, 'announcements')
            ->assertJsonPath('announcements.0.title', 'Aviso vigente');
    }

    public function test_no_requiere_sesion(): void
    {
        foreach (['/api/public/home', '/api/public/news', '/api/public/announcements', '/api/public/faqs'] as $url) {
            $this->getJson($url)->assertOk();
        }
    }
}
