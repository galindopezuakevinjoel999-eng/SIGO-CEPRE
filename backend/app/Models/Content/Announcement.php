<?php

namespace App\Models\Content;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Aviso o comunicado (RF43). Colección "announcements" en MongoDB.
 *
 * @property string $title
 * @property string $content
 * @property string $type      aviso | comunicado
 * @property string $priority  alta | media | baja
 * @property array{scope: string, groups: list<string>, career_codes: list<string>} $audience
 *           scope: todos | grupo | carrera
 * @property ?\Illuminate\Support\Carbon $valid_from   null = desde siempre
 * @property ?\Illuminate\Support\Carbon $valid_until  null = sin vencimiento
 * @property list<array{name: string, path: string, url: string}> $attachments  disco público
 * @property array{id: int, name: string}|null $author
 *           Guardado como author._id en MongoDB (ver News).
 * @property string $status    draft | published
 */
class Announcement extends Model
{
    public const TYPES = ['aviso', 'comunicado'];

    public const PRIORITIES = ['alta', 'media', 'baja'];

    public const SCOPES = ['todos', 'grupo', 'carrera'];

    public const STATUSES = ['draft', 'published'];

    protected $connection = 'mongodb';

    protected $table = 'announcements';

    protected $fillable = [
        'title', 'content', 'type', 'priority', 'audience', 'valid_from', 'valid_until', 'attachments', 'author', 'status',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    protected $attributes = [
        'type' => 'aviso',
        'priority' => 'media',
        'status' => 'draft',
        'attachments' => [],
        'audience' => ['scope' => 'todos', 'groups' => [], 'career_codes' => []],
    ];

    /** Publicados y vigentes hoy: valid_from ≤ ahora ≤ valid_until (null = sin límite). */
    public function scopeCurrent($query)
    {
        $now = now();

        return $query->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now));
    }

    /**
     * Para un grupo y/o carrera: los dirigidos a todos más los que les corresponden.
     */
    public function scopeForAudience($query, ?string $group, ?string $careerCode)
    {
        return $query->where(function ($q) use ($group, $careerCode) {
            $q->where('audience.scope', 'todos');

            if ($group) {
                $q->orWhere(fn ($g) => $g->where('audience.scope', 'grupo')->where('audience.groups', strtoupper($group)));
            }

            if ($careerCode) {
                $q->orWhere(fn ($c) => $c->where('audience.scope', 'carrera')->where('audience.career_codes', strtoupper($careerCode)));
            }
        });
    }

    /** Orden de prioridad para mostrar primero lo urgente. */
    public function priorityRank(): int
    {
        return array_search($this->priority, self::PRIORITIES, true) ?: 0;
    }
}
