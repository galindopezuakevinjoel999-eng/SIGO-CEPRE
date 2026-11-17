<?php

namespace Tests\Feature\Content;

use App\Models\Content\Announcement;
use App\Models\Content\News;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\RefreshMongoDatabase;
use Tests\TestCase;

/** Migración de las tablas SQL news y announcements a MongoDB. */
class ContentMigrationTest extends TestCase
{
    use RefreshDatabase;
    use RefreshMongoDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_11_17_000002_move_public_content_to_mongodb.php');
        $this->migration->down(); // vuelve a tener las tablas SQL para cargarles datos
    }

    private function sqlRow(string $table, array $values): int
    {
        return DB::table($table)->insertGetId([
            'title' => 'Título',
            'content' => '<p>Contenido</p>',
            'status' => 'published',
            'created_at' => '2026-05-01 10:00:00',
            'updated_at' => '2026-05-01 10:00:00',
            ...$values,
        ]);
    }

    public function test_copia_las_noticias_con_slug_resumen_y_autor(): void
    {
        $author = User::factory()->admin()->create(['name' => 'Administrador CEPRE']);
        $this->sqlRow('news', ['title' => 'Inicio de clases', 'author_id' => $author->id, 'published_at' => '2026-05-02 08:00:00']);
        $this->sqlRow('news', ['title' => 'Inicio de clases', 'status' => 'draft']);

        $this->migration->up();

        $news = News::orderBy('legacy_sql_id')->get();
        $this->assertCount(2, $news);
        $this->assertSame(['inicio-de-clases', 'inicio-de-clases-2'], $news->pluck('slug')->all());
        $this->assertSame('Contenido', $news[0]->summary);
        // assertEquals: laravel-mongodb guarda el "id" anidado como "_id" y al leerlo cambia el orden de las claves.
        $this->assertEquals(['id' => $author->id, 'name' => 'Administrador CEPRE'], $news[0]->author);
        $this->assertSame('2026-05-02 08:00:00', $news[0]->published_at->format('Y-m-d H:i:s'));
        $this->assertSame('draft', $news[1]->status);
    }

    public function test_copia_los_comunicados_para_todos(): void
    {
        $this->sqlRow('announcements', ['title' => 'Comunicado 01', 'published_at' => '2026-05-03 09:00:00']);

        $this->migration->up();

        $announcement = Announcement::sole();
        $this->assertSame('Comunicado 01', $announcement->title);
        $this->assertSame('comunicado', $announcement->type);
        $this->assertSame('todos', $announcement->audience['scope']);
        $this->assertSame('2026-05-03', $announcement->valid_from->toDateString());
        $this->assertNull($announcement->valid_until);
    }

    public function test_respalda_en_json_y_elimina_las_tablas(): void
    {
        $this->sqlRow('news', ['title' => 'Noticia respaldada']);
        $this->sqlRow('announcements', ['title' => 'Comunicado respaldado']);

        $this->migration->up();

        $this->assertFalse(Schema::hasTable('news'));
        $this->assertFalse(Schema::hasTable('announcements'));

        $files = $this->privateDisk()->files('backups');
        $this->assertCount(1, $files);
        $backup = json_decode($this->privateDisk()->get($files[0]), true);
        $this->assertSame('Noticia respaldada', $backup['news'][0]['title']);
        $this->assertSame('Comunicado respaldado', $backup['announcements'][0]['title']);
    }

    public function test_reejecutar_no_duplica(): void
    {
        $this->sqlRow('news', ['title' => 'Una sola vez']);
        $this->migration->up();

        // Simula una ejecución interrumpida: la tabla volvió con la misma fila.
        $this->migration->down();
        DB::table('news')->insert(['id' => 1, 'title' => 'Una sola vez', 'content' => 'x', 'status' => 'published']);
        $this->migration->up();

        $this->assertSame(1, News::count());
    }

    public function test_sin_filas_no_crea_respaldo(): void
    {
        $this->migration->up();

        $this->assertFalse(Schema::hasTable('news'));
        $this->assertEmpty($this->privateDisk()->allFiles());
    }
}
