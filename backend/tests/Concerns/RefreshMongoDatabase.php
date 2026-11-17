<?php

namespace Tests\Concerns;

use App\Support\ContentIndexes;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deja vacías las colecciones de contenido en MongoDB antes de cada prueba.
 *
 * Seguridad: solo actúa si la base termina en "_test" (phpunit.xml la define
 * como sigo_cepre_test), para que nunca pueda vaciar la base real.
 *
 * Laravel llama a setUpRefreshMongoDatabase() automáticamente al usar el trait.
 */
trait RefreshMongoDatabase
{
    /** Colecciones que se vacían en cada prueba. */
    protected array $mongoCollections = ['news', 'announcements', 'faqs', 'home_blocks'];

    private static bool $mongoIndexesReady = false;

    protected function setUpRefreshMongoDatabase(): void
    {
        $database = config('database.connections.mongodb.database');

        if (! str_ends_with((string) $database, '_test')) {
            $this->fail("RefreshMongoDatabase solo limpia bases de prueba (*_test); la configurada es \"{$database}\". Revisa MONGODB_DATABASE en phpunit.xml.");
        }

        try {
            foreach ($this->mongoCollections as $collection) {
                DB::connection('mongodb')->table($collection)->delete();
            }

            // Los índices se crean una vez por ejecución (crear uno existente no hace nada).
            if (! self::$mongoIndexesReady) {
                ContentIndexes::ensure();
                self::$mongoIndexesReady = true;
            }
        } catch (Throwable $e) {
            $this->fail(
                "No hay conexión con MongoDB ({$database}): ".class_basename($e).".\n".
                'Si usas Atlas, agrega la IP de este equipo en Network Access. '.
                'Detalle: '.preg_replace('#mongodb(\+srv)?://[^@\s]+@#', 'mongodb://***@', $e->getMessage())
            );
        }
    }
}
