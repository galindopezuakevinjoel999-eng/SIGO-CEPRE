<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('content:indexes', function () {
    App\Support\ContentIndexes::ensure();
    $this->info('Índices de contenido creados en MongoDB ('.config('database.connections.mongodb.database').').');
})->purpose('Crea los índices de las colecciones de contenido en MongoDB');
