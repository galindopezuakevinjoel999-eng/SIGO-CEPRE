<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use MongoDB\Driver\Exception\ConnectionTimeoutException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Catálogo del CEPRE en todos los entornos; datos de prueba solo en local.
     */
    public function run(): void
    {
        $this->call(FoundationSeeder::class);

        // Contenido público en MongoDB Atlas. Si no hay conexión, el resto del
        // sistema (MySQL) queda sembrado igual y se avisa cómo reintentarlo.
        // En las pruebas se omite: las de contenido llaman a ContentSeeder
        // directamente, y así las demás no dependen de Atlas.
        if (! app()->runningUnitTests()) {
            try {
                $this->call(ContentSeeder::class);
            } catch (ConnectionTimeoutException $e) {
                $this->command?->error('ContentSeeder omitido: no hay conexión con MongoDB ('.class_basename($e).').');
                $this->command?->warn('Revisa MONGODB_URI y la lista de IP permitidas en Atlas; luego: php artisan db:seed --class=ContentSeeder');
            }
        }

        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        } else {
            $this->command?->warn('Sin datos de prueba (APP_ENV no es local). Crea el administrador y configura enrollment_cost manualmente.');
        }
    }
}
