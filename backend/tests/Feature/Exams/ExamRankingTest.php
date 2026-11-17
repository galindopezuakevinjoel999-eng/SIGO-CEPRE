<?php

namespace Tests\Feature\Exams;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Ranking general y por grupo, con empates (1, 1, 3). */
class ExamRankingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Semester $semester;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->semester = Semester::factory()->active()->create();
        $this->exam = Exam::factory()->for($this->semester)->create(); // 10 preguntas, +4 / -1 / 0
    }

    /**
     * @param  array<string, array{0: string, 1: int, 2: int, 3: int}>  $rows  dni => [grupo, correctas, incorrectas, en blanco]
     */
    private function importRows(array $rows): void
    {
        $careers = [];
        $csv = "dni,correctas,incorrectas,en_blanco\n";

        foreach ($rows as $dni => [$group, $correct, $wrong, $blank]) {
            $careers[$group] ??= Career::factory()->create(['group' => $group]);
            $student = User::factory()->student()->create(['dni' => (string) $dni]);
            Enrollment::factory()->approved()->for($student, 'student')->create(['career_id' => $careers[$group]->id, 'semester_id' => $this->semester->id]);
            $csv .= "{$dni},{$correct},{$wrong},{$blank}\n";
        }

        $this->actingAs($this->admin)
            ->postJson("/api/exams/{$this->exam->id}/import", ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])
            ->assertOk();
    }

    /** @return array<string, array{0: float, 1: int, 2: int}> dni => [puntaje, puesto, puesto en grupo] */
    private function ranking(): array
    {
        return ExamResult::with('student')->get()
            ->mapWithKeys(fn (ExamResult $r) => [$r->student->dni => [$r->score, $r->rank, $r->rank_in_group]])
            ->sortKeys()
            ->all();
    }

    public function test_ranking_general_y_por_grupo_con_empates(): void
    {
        $this->importRows([
            '70000001' => ['A', 9, 1, 0],   // 35
            '70000002' => ['B', 9, 1, 0],   // 35  empata con el anterior
            '70000003' => ['A', 8, 0, 2],   // 32
            '70000004' => ['A', 8, 0, 2],   // 32  empate en el grupo A
            '70000005' => ['B', 7, 3, 0],   // 25
            '70000006' => ['A', 2, 8, 0],   // 0
        ]);

        $this->assertSame([
            '70000001' => [35.0, 1, 1],
            '70000002' => [35.0, 1, 1],
            '70000003' => [32.0, 3, 2],   // dos empatados en 1.º → el siguiente es 3.º
            '70000004' => [32.0, 3, 2],
            '70000005' => [25.0, 5, 2],
            '70000006' => [0.0, 6, 4],    // en el grupo A: 1, 2, 2 → el siguiente es 4.º
        ], $this->ranking());
    }

    public function test_todos_empatados_comparten_el_primer_puesto(): void
    {
        $this->importRows([
            '70000001' => ['A', 5, 5, 0],
            '70000002' => ['A', 5, 5, 0],
            '70000003' => ['B', 5, 5, 0],
        ]);

        $this->assertSame([[1, 1], [1, 1], [1, 1]], array_values(array_map(fn ($r) => [$r[1], $r[2]], $this->ranking())));
    }

    public function test_puntajes_negativos_quedan_al_final(): void
    {
        $this->importRows([
            '70000001' => ['A', 0, 10, 0],  // -10
            '70000002' => ['A', 0, 0, 10],  // 0
            '70000003' => ['A', 1, 9, 0],   // -5
        ]);

        $this->assertSame([
            '70000001' => [-10.0, 3, 3],
            '70000002' => [0.0, 1, 1],
            '70000003' => [-5.0, 2, 2],
        ], $this->ranking());
    }

    public function test_puntajes_con_decimales(): void
    {
        $this->exam->update(['points_correct' => 2.5, 'points_wrong' => -0.625]);

        $this->importRows([
            '70000001' => ['A', 7, 3, 0],   // 17.5 − 1.875 = 15.625
            '70000002' => ['A', 6, 0, 4],   // 15
        ]);

        $this->assertSame([
            '70000001' => [15.625, 1, 1],
            '70000002' => [15.0, 2, 2],
        ], $this->ranking());
    }

    public function test_cambiar_los_puntos_recalcula_puntajes_y_ranking(): void
    {
        $this->importRows([
            '70000001' => ['A', 6, 4, 0],   // +4/-1: 20  | +1/0: 6
            '70000002' => ['A', 5, 0, 5],   // +4/-1: 20  | +1/0: 5
        ]);
        $this->assertSame([1, 1], array_column($this->ranking(), 1));

        $this->actingAs($this->admin)
            ->putJson("/api/exams/{$this->exam->id}", ['points_correct' => 1, 'points_wrong' => 0])
            ->assertOk();

        $this->assertSame([
            '70000001' => [6.0, 1, 1],
            '70000002' => [5.0, 2, 2],
        ], $this->ranking());
    }

    public function test_el_ranking_se_consulta_y_filtra_por_grupo(): void
    {
        $this->importRows([
            '70000001' => ['A', 9, 1, 0],
            '70000002' => ['B', 8, 2, 0],
            '70000003' => ['A', 7, 3, 0],
        ]);

        $this->actingAs(User::factory()->coordinator()->create())
            ->getJson("/api/exams/{$this->exam->id}/results")
            ->assertOk()
            ->assertJsonPath('data.0.student.dni', '70000001')
            ->assertJsonPath('data.0.rank', 1)
            ->assertJsonCount(3, 'data');

        $this->actingAs($this->admin)
            ->getJson("/api/exams/{$this->exam->id}/results?group=a")
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.rank_in_group', 2);

        $this->actingAs(User::factory()->student()->create())
            ->getJson("/api/exams/{$this->exam->id}/results")
            ->assertForbidden();
    }
}
