<?php

namespace App\Models\Content;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Bloque de la página de inicio (RF44, RF45). Colección "home_blocks".
 * "data" es libre: cada tipo de bloque define su propia forma (ver ContentSeeder).
 *
 * @property string $type   banner | fechas | carreras | cta
 * @property int $order
 * @property array $data
 * @property bool $active
 */
class HomeBlock extends Model
{
    public const TYPES = ['banner', 'fechas', 'carreras', 'cta'];

    protected $connection = 'mongodb';

    protected $table = 'home_blocks';

    protected $fillable = ['type', 'order', 'data', 'active'];

    protected $casts = [
        'order' => 'integer',
        'active' => 'boolean',
    ];

    protected $attributes = [
        'order' => 0,
        'data' => [],
        'active' => true,
    ];
}
