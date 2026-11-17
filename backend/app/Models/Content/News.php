<?php

namespace App\Models\Content;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MongoDB\Laravel\Eloquent\Model;

/**
 * Noticia del sitio público (RF42, RF44). Colección "news" en MongoDB.
 *
 * @property string $title
 * @property string $slug          único (índice en ContentIndexes)
 * @property ?string $summary
 * @property string $content
 * @property ?string $cover_image  ruta en el disco público
 * @property list<string> $tags
 * @property array{id: int, name: string}|null $author
 *           En MongoDB se guarda como author._id (laravel-mongodb renombra el "id"
 *           anidado); el modelo lo devuelve como "id". Para filtrar: where('author._id', $id).
 * @property string $status        draft | published
 * @property ?\Illuminate\Support\Carbon $published_at
 */
class News extends Model
{
    public const STATUSES = ['draft', 'published'];

    protected $connection = 'mongodb';

    protected $table = 'news';

    protected $fillable = [
        'title', 'slug', 'summary', 'content', 'cover_image', 'tags', 'author', 'status', 'published_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected $appends = ['cover_image_url'];

    protected $attributes = [
        'status' => 'draft',
        'tags' => [],
    ];

    /** Publicadas y con fecha de publicación ya alcanzada (permite programarlas). */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')->where('published_at', '<=', now());
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image ? Storage::disk('public')->url($this->cover_image) : null;
    }

    /** Slug único a partir del título: "examen-de-admision", "examen-de-admision-2"... */
    public static function uniqueSlug(string $text, ?string $ignoreId = null): string
    {
        $base = Str::slug($text) ?: 'noticia';
        $slug = $base;

        for ($i = 2; static::slugTaken($slug, $ignoreId); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    public static function slugTaken(string $slug, ?string $ignoreId = null): bool
    {
        return static::where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->where('_id', '!=', $ignoreId))
            ->exists();
    }
}
