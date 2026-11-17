<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Exámenes y resultados (RF30–RF34) reemplazan a la tabla "grades".
 *
 *  exams         examen (simulacro, parcial o final) con los puntos por
 *                respuesta correcta, incorrecta y en blanco.
 *  exam_results  resultado de cada estudiante: respuestas, puntaje y puestos
 *                en el ranking general y de su grupo.
 *
 * Las filas de "grades" se respaldan en storage/app/private/backups y se
 * convierten: un examen publicado por cada evaluación (campo "assessment")
 * y semestre, con el puntaje original. Como grades no guardaba respuestas,
 * correct/wrong/blank quedan vacíos y los puntos del examen en 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['simulacro', 'parcial', 'final']);
            $table->date('date');
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
            $table->char('group', 1)->nullable();                  // A, B, C o null = todos los grupos
            $table->unsignedSmallInteger('total_questions')->nullable();
            // Valor que se suma por respuesta; una penalización es negativa (ej. -1.125).
            $table->decimal('points_correct', 8, 3);
            $table->decimal('points_wrong', 8, 3);
            $table->decimal('points_blank', 8, 3)->default(0);
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('results_imported_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['semester_id', 'status']);
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('correct')->nullable();
            $table->unsignedSmallInteger('wrong')->nullable();
            $table->unsignedSmallInteger('blank')->nullable();
            $table->decimal('score', 10, 3);
            $table->char('group', 1)->nullable();                  // grupo del estudiante al rendirlo
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedInteger('rank_in_group')->nullable();
            $table->timestamps();

            $table->unique(['exam_id', 'student_id']);
            $table->index(['exam_id', 'rank']);
            $table->index(['student_id']);
        });

        $this->migrateGrades();

        Schema::dropIfExists('grades');
    }

    public function down(): void
    {
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->string('assessment')->nullable();
            $table->decimal('score', 5, 2)->default(0);
            $table->decimal('weight', 5, 2)->default(1);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exams');
    }

    private function migrateGrades(): void
    {
        if (! Schema::hasTable('grades')) {
            return;
        }

        $grades = DB::table('grades')
            ->leftJoin('enrollments', 'enrollments.id', '=', 'grades.enrollment_id')
            ->select('grades.*', 'enrollments.semester_id')
            ->orderBy('grades.id')
            ->get();

        if ($grades->isEmpty()) {
            return;
        }

        Storage::disk('private')->put(
            'backups/sql-grades-'.now()->format('Ymd_His').'.json',
            json_encode(['exported_at' => now()->toIso8601String(), 'grades' => $grades], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $grades
            ->groupBy(fn ($g) => ($g->assessment ?: 'Evaluación').'|'.($g->semester_id ?? ''))
            ->each(fn (Collection $rows) => $this->migrateAssessment($rows));
    }

    private function migrateAssessment(Collection $rows): void
    {
        $first = $rows->first();
        $now = now();

        $examId = DB::table('exams')->insertGetId([
            'name' => $first->assessment ?: 'Evaluación',
            'type' => 'parcial',
            'date' => Carbon::parse($rows->min('created_at') ?? $now)->toDateString(),
            'semester_id' => $first->semester_id,
            'points_correct' => 0,
            'points_wrong' => 0,
            'points_blank' => 0,
            'status' => 'published', // las notas ya eran visibles para los estudiantes
            'results_imported_at' => $now,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Una nota por estudiante y evaluación: si había varias, queda la más reciente.
        $rows->groupBy('student_id')->each(function (Collection $studentRows) use ($examId, $now) {
            $grade = $studentRows->sortBy('id')->last();

            DB::table('exam_results')->insert([
                'exam_id' => $examId,
                'student_id' => $grade->student_id,
                'score' => $grade->score,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        // Ranking con empates (1, 1, 3). Sin grupo conocido, no hay ranking por grupo.
        $position = 0;
        $previous = null;
        $rank = 0;
        foreach (DB::table('exam_results')->where('exam_id', $examId)->orderByDesc('score')->get() as $result) {
            $position++;
            if ($result->score !== $previous) {
                $rank = $position;
                $previous = $result->score;
            }
            DB::table('exam_results')->where('id', $result->id)->update(['rank' => $rank]);
        }
    }
};
