<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclos del CEPRE UNAJMA (cepreunajma.com).
 *
 *  semesters:
 *   - type: intensivo | ordinario_i | ordinario_ii (código 2X0 / 2X1 / 2X2).
 *   - enrollment_start / enrollment_end: periodo de inscripción.
 *   - classes_start / classes_end: periodo de clases (antes start_date / end_date).
 *   - min_admission_score: puntaje mínimo para ser ingresante (null = sin mínimo).
 *   - payment_plans: modalidades de pago del ciclo, p. ej.
 *       {"unico": {"label": "Pago único", "installments": [600]},
 *        "fraccionado": {"label": "Pago fraccionado", "installments": [500, 150]}}
 *  career_semester: vacantes de cada carrera en cada ciclo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semesters', function (Blueprint $table) {
            $table->string('type', 20)->nullable()->after('code');
            $table->date('enrollment_start')->nullable()->after('name');
            $table->date('enrollment_end')->nullable()->after('enrollment_start');
            $table->decimal('min_admission_score', 10, 3)->nullable();
            $table->json('payment_plans')->nullable();
        });

        Schema::table('semesters', function (Blueprint $table) {
            $table->renameColumn('start_date', 'classes_start');
        });
        Schema::table('semesters', function (Blueprint $table) {
            $table->renameColumn('end_date', 'classes_end');
        });

        // El ciclo 0 es el intensivo; 1 y 2, los ordinarios.
        DB::table('semesters')->where('cycle', 0)->update(['type' => 'intensivo']);
        DB::table('semesters')->where('cycle', 1)->update(['type' => 'ordinario_i']);
        DB::table('semesters')->where('cycle', 2)->update(['type' => 'ordinario_ii']);

        Schema::create('career_semester', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained('careers')->cascadeOnDelete();
            $table->foreignId('semester_id')->constrained('semesters')->cascadeOnDelete();
            $table->unsignedSmallInteger('vacancies');
            $table->timestamps();

            $table->unique(['career_id', 'semester_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_semester');

        Schema::table('semesters', function (Blueprint $table) {
            $table->renameColumn('classes_end', 'end_date');
        });
        Schema::table('semesters', function (Blueprint $table) {
            $table->renameColumn('classes_start', 'start_date');
        });
        Schema::table('semesters', function (Blueprint $table) {
            $table->dropColumn(['type', 'enrollment_start', 'enrollment_end', 'min_admission_score', 'payment_plans']);
        });
    }
};
