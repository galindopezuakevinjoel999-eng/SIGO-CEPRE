<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normaliza las relaciones académicas: reemplaza los campos de texto
 * (career, semester, classroom) por llaves foráneas reales.
 *
 * Antes de eliminar cada columna de texto, intenta convertir su valor
 * al id correspondiente (buscando por código, número o nombre), para no
 * perder datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- courses ----------
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('career_id')->nullable()->after('name')->constrained('careers')->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->after('career_id')->constrained('semesters')->nullOnDelete();
        });
        $this->mapTextToForeignKey('courses', 'career', 'career_id', 'careers', ['code', 'name']);
        $this->mapTextToForeignKey('courses', 'semester', 'semester_id', 'semesters', ['code', 'name']);
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['career', 'semester']);
        });

        // ---------- enrollments ----------
        // La columna de texto "career" ocultaba la relación career() del modelo.
        $this->mapTextToForeignKey('enrollments', 'career', 'career_id', 'careers', ['code', 'name']);
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_id'); // la matrícula es por carrera, no por curso
        });
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('career');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });

        // ---------- schedules ----------
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('career_id')->nullable()->after('teacher_id')->constrained('careers')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->after('career_id')->constrained('classrooms')->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->after('classroom_id')->constrained('semesters')->nullOnDelete();
        });
        $this->mapTextToForeignKey('schedules', 'career', 'career_id', 'careers', ['code', 'name']);
        $this->mapTextToForeignKey('schedules', 'classroom', 'classroom_id', 'classrooms', ['number', 'name']);
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['career', 'classroom']);
        });

        // ---------- attendances ----------
        Schema::table('attendances', function (Blueprint $table) {
            $table->foreignId('schedule_id')->nullable()->after('course_id')->constrained('schedules')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
            $table->unique(['student_id', 'schedule_id', 'date'], 'attendances_student_schedule_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_student_schedule_date_unique');
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropConstrainedForeignId('recorded_by');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->string('career')->nullable();
            $table->string('classroom')->nullable();
            $table->dropConstrainedForeignId('career_id');
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropConstrainedForeignId('semester_id');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
            $table->string('career')->nullable();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->string('career')->nullable();
            $table->string('semester')->nullable();
            $table->dropConstrainedForeignId('career_id');
            $table->dropConstrainedForeignId('semester_id');
        });
    }

    /**
     * Copia el valor de una columna de texto a su llave foránea,
     * buscando coincidencias en las columnas indicadas de la tabla referenciada.
     */
    private function mapTextToForeignKey(string $table, string $textColumn, string $foreignKey, string $refTable, array $refColumns): void
    {
        DB::table($table)
            ->whereNotNull($textColumn)
            ->whereNull($foreignKey)
            ->orderBy('id')
            ->each(function ($row) use ($table, $textColumn, $foreignKey, $refTable, $refColumns) {
                $value = trim((string) $row->{$textColumn});
                if ($value === '') {
                    return;
                }

                $refId = DB::table($refTable)
                    ->where(function ($query) use ($refColumns, $value) {
                        foreach ($refColumns as $column) {
                            $query->orWhere($column, $value);
                        }
                    })
                    ->value('id');

                if ($refId) {
                    DB::table($table)->where('id', $row->id)->update([$foreignKey => $refId]);
                }
            });
    }
};
