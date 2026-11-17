<?php

namespace Tests\Feature\Exams;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Las filas de "grades" pasan a exams + exam_results. */
class GradesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_11_18_000001_create_exams_replace_grades.php');
        $this->migration->down(); // vuelve a la tabla grades
    }

    private function grade(User $student, ?string $assessment, float $score, ?Enrollment $enrollment = null, string $createdAt = '2026-05-10 10:00:00'): void
    {
        DB::table('grades')->insert([
            'student_id' => $student->id,
            'enrollment_id' => $enrollment?->id,
            'assessment' => $assessment,
            'score' => $score,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    public function test_convierte_cada_evaluacion_en_un_examen_publicado_con_ranking(): void
    {
        $semester = Semester::factory()->create();
        [$ana, $luis, $rosa] = User::factory()->student()->count(3)->create()->all();
        $enrollment = fn (User $s) => Enrollment::factory()->approved()->for($s, 'student')->create(['semester_id' => $semester->id]);

        $this->grade($ana, 'Primer examen', 16, $enrollment($ana), '2026-05-02 09:00:00');
        $this->grade($luis, 'Primer examen', 18, $enrollment($luis));
        $this->grade($rosa, 'Primer examen', 16, $enrollment($rosa));

        $this->migration->up();

        $exam = Exam::sole();
        $this->assertSame('Primer examen', $exam->name);
        $this->assertSame($semester->id, $exam->semester_id);
        $this->assertTrue($exam->isPublished());
        $this->assertSame('2026-05-02', $exam->date->toDateString());

        $ranks = ExamResult::pluck('rank', 'student_id')->all();
        $this->assertSame([$luis->id => 1, $ana->id => 2, $rosa->id => 2], [$luis->id => $ranks[$luis->id], $ana->id => $ranks[$ana->id], $rosa->id => $ranks[$rosa->id]]);
        $this->assertNull(ExamResult::first()->correct, 'grades no guardaba respuestas');
    }

    public function test_separa_por_evaluacion_y_conserva_la_nota_mas_reciente(): void
    {
        $ana = User::factory()->student()->create();
        $this->grade($ana, 'Parcial', 12);
        $this->grade($ana, 'Parcial', 15);  // corregida después: queda esta
        $this->grade($ana, 'Final', 17);
        $this->grade($ana, null, 10);        // sin nombre

        $this->migration->up();

        $this->assertEqualsCanonicalizing(['Parcial', 'Final', 'Evaluación'], Exam::pluck('name')->all());
        $this->assertSame(15.0, ExamResult::whereHas('exam', fn ($q) => $q->where('name', 'Parcial'))->sole()->score);
    }

    public function test_respalda_y_elimina_la_tabla_grades(): void
    {
        $this->grade(User::factory()->student()->create(), 'Parcial', 14);

        $this->migration->up();

        $this->assertFalse(Schema::hasTable('grades'));
        $backup = json_decode($this->privateDisk()->get($this->privateDisk()->files('backups')[0]), true);
        $this->assertSame('Parcial', $backup['grades'][0]['assessment']);
    }

    public function test_sin_notas_no_crea_examenes_ni_respaldo(): void
    {
        $this->migration->up();

        $this->assertSame(0, Exam::count());
        $this->assertEmpty($this->privateDisk()->allFiles());
    }
}
