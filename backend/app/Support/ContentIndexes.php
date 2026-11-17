<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Índices de las colecciones de contenido en MongoDB.
 *
 * No están en una migración a propósito: las migraciones corren en cada
 * ejecución de las pruebas (RefreshDatabase) y harían que todas dependieran de
 * MongoDB Atlas. Se crean con `php artisan content:indexes`, desde
 * ContentSeeder y en las pruebas de contenido. Crear un índice que ya existe
 * no hace nada, así que se puede ejecutar las veces que haga falta.
 */
class ContentIndexes
{
    public static function ensure(): void
    {
        $schema = Schema::connection('mongodb');

        $schema->table('news', function (Blueprint $collection) {
            $collection->unique('slug');
            $collection->index(['status' => 1, 'published_at' => -1]);
        });

        $schema->table('announcements', function (Blueprint $collection) {
            $collection->index(['status' => 1, 'valid_from' => 1, 'valid_until' => 1]);
            $collection->index('audience.scope');
        });

        $schema->table('faqs', function (Blueprint $collection) {
            $collection->index(['active' => 1, 'category' => 1, 'order' => 1]);
        });

        $schema->table('home_blocks', function (Blueprint $collection) {
            $collection->index(['active' => 1, 'order' => 1]);
        });
    }
}
