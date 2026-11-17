<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * El contenido público pasa a MongoDB (colecciones "news" y "announcements").
 *
 * Orden, para no perder datos:
 *  1. Respaldo de ambas tablas en storage/app/private/backups/*.json
 *  2. Copia a MongoDB (cada documento guarda legacy_sql_id; si se re-ejecuta,
 *     no duplica) y comprobación de que la cantidad coincide.
 *  3. Recién entonces se eliminan las tablas SQL.
 * Si MongoDB falla en el paso 2, la migración se detiene y las tablas quedan.
 *
 * Sin filas no se conecta a MongoDB: así las pruebas (que migran desde cero
 * en SQLite) no dependen de Atlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $news = Schema::hasTable('news') ? DB::table('news')->orderBy('id')->get() : collect();
        $announcements = Schema::hasTable('announcements') ? DB::table('announcements')->orderBy('id')->get() : collect();

        if ($news->isNotEmpty() || $announcements->isNotEmpty()) {
            $this->backup($news, $announcements);
            $this->copyNews($news);
            $this->copyAnnouncements($announcements);
        }

        Schema::dropIfExists('news');
        Schema::dropIfExists('announcements');
    }

    /**
     * Recrea las tablas vacías. Los datos siguen en MongoDB (con legacy_sql_id)
     * y en el respaldo JSON; no se copian de vuelta.
     */
    public function down(): void
    {
        foreach (['news', 'announcements'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('title');
                $table->text('content');
                $table->enum('status', ['draft', 'published'])->default('published');
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }
    }

    private function backup(Collection $news, Collection $announcements): void
    {
        $path = 'backups/sql-public-content-'.now()->format('Ymd_His').'.json';

        Storage::disk('private')->put($path, json_encode([
            'exported_at' => now()->toIso8601String(),
            'news' => $news,
            'announcements' => $announcements,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function copyNews(Collection $rows): void
    {
        $collection = DB::connection('mongodb')->table('news');
        $usedSlugs = $collection->pluck('slug')->all();

        foreach ($rows as $row) {
            if ($collection->clone()->where('legacy_sql_id', $row->id)->exists()) {
                continue;
            }

            $slug = $this->uniqueSlug($row->title, $usedSlugs);
            $usedSlugs[] = $slug;

            $collection->clone()->insert([[ // lista explícita: insert() trata un array como lote si su primer valor es un array
                'legacy_sql_id' => $row->id,
                'title' => $row->title,
                'slug' => $slug,
                'summary' => Str::limit(trim(strip_tags($row->content)), 200),
                'content' => $row->content,
                'cover_image' => null,
                'tags' => [],
                'author' => $this->author($row->author_id),
                'status' => $row->status,
                // Una noticia publicada sin fecha se considera publicada al crearse.
                'published_at' => $this->date($row->published_at ?? ($row->status === 'published' ? $row->created_at : null)),
                'created_at' => $this->date($row->created_at),
                'updated_at' => $this->date($row->updated_at),
            ]]);
        }

        $this->assertCopied('news', $rows);
    }

    private function copyAnnouncements(Collection $rows): void
    {
        $collection = DB::connection('mongodb')->table('announcements');

        foreach ($rows as $row) {
            if ($collection->clone()->where('legacy_sql_id', $row->id)->exists()) {
                continue;
            }

            $collection->clone()->insert([[
                'legacy_sql_id' => $row->id,
                'title' => $row->title,
                'content' => $row->content,
                // La tabla SQL no distinguía tipos ni destinatarios.
                'type' => 'comunicado',
                'priority' => 'media',
                'audience' => ['scope' => 'todos', 'groups' => [], 'career_codes' => []],
                'valid_from' => $this->date($row->published_at ?? $row->created_at),
                'valid_until' => null,
                'attachments' => [],
                'author' => $this->author($row->author_id),
                'status' => $row->status,
                'created_at' => $this->date($row->created_at),
                'updated_at' => $this->date($row->updated_at),
            ]]);
        }

        $this->assertCopied('announcements', $rows);
    }

    /** Antes de borrar la tabla, todas sus filas deben estar en MongoDB. */
    private function assertCopied(string $collection, Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $copied = DB::connection('mongodb')->table($collection)
            ->whereIn('legacy_sql_id', $rows->pluck('id')->all())
            ->count();

        if ($copied !== $rows->count()) {
            throw new RuntimeException("Solo {$copied} de {$rows->count()} filas de {$collection} llegaron a MongoDB. No se eliminó la tabla.");
        }
    }

    private function uniqueSlug(string $title, array $used): string
    {
        $base = Str::slug($title) ?: 'noticia';
        $slug = $base;

        for ($i = 2; in_array($slug, $used, true); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** @return array{id: int, name: string}|null */
    private function author(?int $userId): ?array
    {
        $name = $userId ? DB::table('users')->where('id', $userId)->value('name') : null;

        return $name ? ['id' => $userId, 'name' => $name] : null;
    }

    private function date(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
};
