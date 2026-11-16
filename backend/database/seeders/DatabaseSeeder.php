<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Catálogo del CEPRE en todos los entornos; datos de prueba solo en local.
     */
    public function run(): void
    {
        $this->call(FoundationSeeder::class);

        if (app()->isLocal()) {
            $this->call(DemoSeeder::class);
        } else {
            $this->command?->warn('Sin datos de prueba (APP_ENV no es local). Crea el administrador y configura enrollment_cost manualmente.');
        }
    }
}
