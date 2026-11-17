<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla "reports" guardaba metadatos de reportes que nunca se generaban.
 * Ahora los reportes se generan al pedirlos (GET /api/admin/reports/{tipo}),
 * así que la tabla se elimina. Estaba vacía; si tuviera filas, la migración
 * se detiene para no perderlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reports')) {
            return;
        }

        $rows = \Illuminate\Support\Facades\DB::table('reports')->count();
        if ($rows > 0) {
            throw new RuntimeException("La tabla reports tiene {$rows} filas: respáldalas antes de eliminarla.");
        }

        Schema::drop('reports');
    }

    public function down(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_type');
            $table->string('title')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('filters')->nullable();
            $table->json('data')->nullable();
            $table->enum('format', ['pdf', 'csv', 'json'])->default('json');
            $table->string('file_url')->nullable();
            $table->timestamps();
        });
    }
};
