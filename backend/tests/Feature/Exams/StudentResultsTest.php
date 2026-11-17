<?php

namespace Tests\Feature\Exams;

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** El estudiante ve sus resultados publicados e historial por semestre (RF33, RF34). */
class StudentResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Semester $previous;

    private Semester $current;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student()->create();
        $this->previous = Semester::factory()->create(['code' => '261', 'year' => 2026, 'cycle' => 1, 'name' => '2026-I']);
        $this->current = Semester::factory()->active()->create(['code' => '262', 'year' => 2026, 'cycle' => 2, 'name' => '2026-II']);
    }

    private function examResult(Semester $semester, string $name, float $score, bool $published = true, ?User $student = null, string $date = '2026-10-01'): ExamResult
    {
        $exam = Exam::factory()->for($semester)->create([
            'name' => $name,
            'date' => $date,
            'status' => $published ? Exam::STATUS_PUBLISHED : Exam::STATUS_DRAFT,
        ]);

        return ExamResult::factory()->for($exam)->create([
            'student_id' => ($student ?? $this->student)->id,
            'score' => $score,
        ]);
    }

    public function test_solo_ve_resultados_de_examenes_publicados(): void
    {
        $this->examResult($this->current, 'Simulacro 1', 30);
        $this->examResult($this->current, 'Simulacro 2 (sin publicar)', 35, published: false);

        $history = $this->actingAs($this->student)->getJson('/api/me/exam-results')->assertOk()->json();

        $this->assertCount(1, $history);
        $this->assertSame(['Simulacro 1'], array_column(array_column($history[0]['results'], 'exam'), 'name'));
    }

    public function test_historial_agrupado_por_semestre_del_mas_reciente_al_mas_antiguo(): void
    {
        $this->examResult($this->previous, 'Final 2026-I', 28, date: '2026-07-10');
        $this->examResult($this->current, 'Simulacro 1', 30, date: '2026-09-10');
        $this->examResult($this->current, 'Parcial', 34, date: '2026-10-15');

        $history = $this->actingAs($this->student)->getJson('/api/me/exam-results')->assertOk()->json();

        $this->assertSame(['2026-II', '2026-I'], array_column(array_column($history, 'semester'), 'name'));
        $this->assertSame(['Parcial', 'Simulacro 1'], array_column(array_column($history[0]['results'], 'exam'), 'name'));
        $this->assertEquals(32, $history[0]['average_score']);
    }

    public function test_filtra_por_semestre(): void
    {
        $this->examResult($this->previous, 'Final 2026-I', 28);
        $this->examResult($this->current, 'Simulacro 1', 30);

        $this->actingAs($this->student)
            ->getJson("/api/me/exam-results?semester_id={$this->previous->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.semester.code', '261');
    }

    public function test_muestra_su_puesto_y_el_total_de_participantes(): void
    {
        $mine = $this->examResult($this->current, 'Simulacro 1', 30);
        $mine->update(['rank' => 2, 'group' => 'A', 'rank_in_group' => 1]);
        ExamResult::factory()->for($mine->exam)->create(['rank' => 1, 'group' => 'B']);
        ExamResult::factory()->for($mine->exam)->create(['rank' => 3, 'group' => 'A']);

        $this->actingAs($this->student)->getJson('/api/me/exam-results')
            ->assertJsonPath('0.results.0.rank', 2)
            ->assertJsonPath('0.results.0.participants', 3)
            ->assertJsonPath('0.results.0.rank_in_group', 1)
            ->assertJsonPath('0.results.0.group_participants', 2);
    }

    public function test_no_ve_resultados_de_otros_estudiantes(): void
    {
        $this->examResult($this->current, 'Simulacro 1', 40, student: User::factory()->student()->create());

        $this->actingAs($this->student)->getJson('/api/me/exam-results')->assertOk()->assertJsonCount(0);
    }

    public function test_publicar_hace_visibles_los_resultados(): void
    {
        $result = $this->examResult($this->current, 'Simulacro 1', 30, published: false);
        $this->actingAs($this->student)->getJson('/api/me/exam-results')->assertJsonCount(0);

        $this->actingAs(User::factory()->admin()->create())->putJson("/api/exams/{$result->exam_id}/publish")->assertOk();

        $this->actingAs($this->student)->getJson('/api/me/exam-results')->assertJsonCount(1);
    }
}
