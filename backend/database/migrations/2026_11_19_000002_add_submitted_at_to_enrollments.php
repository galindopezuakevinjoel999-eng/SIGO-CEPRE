<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * submitted_at: cuando el estudiante confirma el paso 4 por primera vez y la
 * matrícula queda lista para que la administración la revise.
 *
 * Separa el indicador D1 en tiempo de registro del estudiante
 * (created_at → submitted_at) y tiempo de atención administrativa
 * (submitted_at → approved_at). Las matrículas anteriores quedan sin fecha:
 * no se inventa una, y esos casos no entran en los nuevos indicadores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
