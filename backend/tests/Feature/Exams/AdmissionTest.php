<?php

namespace Tests\Feature\Exams;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Ranking por carrera e ingresantes: los mejores de cada carrera hasta cubrir
 * sus vacantes, solo si alcanzan el puntaje mínimo del ciclo.
 * Examen de prueba: 10 preguntas, +4 / −1 / 0.
 */
class AdmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Semester $cycle;

    private Exam $exam;

    private Career $sis;

    private Career $psi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->cycle = Semester::factory()->active()->create();
        $this->exam = Exam::factory()->for($this->cycle)->create(['name' => 'Examen final']);
        $this->sis = Career::factory()->create(['code' => 'SIS', 'name' => 'Ingeniería de Sistemas', 'group' => 'A']);
        $this->psi = Career::factory()->create(['code' => 'PSI', 'name' => 'Psicología', 'group' => 'B']);
        $this->cycle->careers()->attach([$this->sis->id => ['vacancies' => 3], $this->psi->id => ['vacancies' => 2]]);
    }

    /** @param  array<string, array{0: Career, 1: int, 2: int}>  $rows  dni => [carrera, correctas, incorrectas] */
    private function import(array $rows): void
    {
        $csv = "dni,correctas,incorrectas,en_blanco\n";
        foreach ($rows as $dni => [$career, $correct, $wrong]) {
            $student = User::factory()->student()->create(['dni' => (string) $dni, 'name' => "Estudiante {$dni}"]);
            Enrollment::factory()->approved()->for($student, 'student')->create(['career_id' => $career->id, 'semester_id' => $this->cycle->id]);
            $csv .= "{$dni},{$correct},{$wrong},".(10 - $correct - $wrong)."\n";
        }

        $this->actingAs($this->admin)
            ->postJson("/api/exams/{$this->exam->id}/import", ['file' => UploadedFile::fake()->createWithContent('r.csv', $csv)])
            ->assertOk();
    }

    private function admitted(): array
    {
        $careers = $this->actingAs($this->admin)->getJson("/api/exams/{$this->exam->id}/admitted")->assertOk()->json('careers');

        return collect($careers)->mapWithKeys(fn ($c) => [$c['career']['code'] => [
            'admitted' => array_column(array_column($c['admitted'], 'student'), 'dni'),
            'filled' => $c['filled'],
            'tie' => $c['tie_at_cutoff'],
        ]])->all();
    }

    public function test_el_ranking_por_carrera_se_calcula_al_importar(): void
    {
        $this->import([
            '70000001' => [$this->sis, 9, 1],   // 35
            '70000002' => [$this->psi, 10, 0],  // 40
            '70000003' => [$this->sis, 7, 0],   // 28
        ]);

        $ranks = $this->exam->results()->with('student')->get()->mapWithKeys(fn ($r) => [$r->student->dni => [$r->rank, $r->rank_in_career, $r->career_id]])->all();

        $this->assertSame([2, 1, $this->sis->id], $ranks['70000001']); // 2.º general, 1.º en Sistemas
        $this->assertSame([1, 1, $this->psi->id], $ranks['70000002']);
        $this->assertSame([3, 2, $this->sis->id], $ranks['70000003']);
    }

    public function test_ingresan_los_mejores_hasta_cubrir_las_vacantes(): void
    {
        $this->import([
            '70000001' => [$this->sis, 10, 0],  // 40
            '70000002' => [$this->sis, 9, 1],   // 35
            '70000003' => [$this->sis, 8, 0],   // 32
            '70000004' => [$this->sis, 7, 3],   // 25  4.º: sin vacante (3)
            '70000005' => [$this->psi, 9, 0],   // 36
            '70000006' => [$this->psi, 8, 2],   // 30
            '70000007' => [$this->psi, 6, 4],   // 20  3.º: sin vacante (2)
        ]);

        $this->assertSame([
            'SIS' => ['admitted' => ['70000001', '70000002', '70000003'], 'filled' => 3, 'tie' => false],
            'PSI' => ['admitted' => ['70000005', '70000006'], 'filled' => 2, 'tie' => false],
        ], array_intersect_key($this->admitted(), ['SIS' => 0, 'PSI' => 0]));
    }

    public function test_solo_ingresan_si_alcanzan_el_puntaje_minimo(): void
    {
        $this->cycle->update(['min_admission_score' => 30]);

        $this->import([
            '70000001' => [$this->sis, 10, 0],  // 40
            '70000002' => [$this->sis, 8, 2],   // 30  justo el mínimo: ingresa
            '70000003' => [$this->sis, 7, 1],   // 27  hay vacante, pero no alcanza
        ]);

        $sis = $this->admitted()['SIS'];
        $this->assertSame(['70000001', '70000002'], $sis['admitted']);
        $this->assertSame(2, $sis['filled']);
    }

    public function test_empate_en_la_ultima_vacante_ingresan_todos_los_empatados(): void
    {
        $this->import([
            '70000001' => [$this->psi, 10, 0],  // 40
            '70000002' => [$this->psi, 8, 0],   // 32  2.º
            '70000003' => [$this->psi, 8, 0],   // 32  2.º (empate en la última vacante)
            '70000004' => [$this->psi, 5, 0],   // 20
        ]);

        $psi = $this->admitted()['PSI'];
        $this->assertSame(['70000001', '70000002', '70000003'], $psi['admitted']);
        $this->assertSame(3, $psi['filled']);   // supera sus 2 vacantes
        $this->assertTrue($psi['tie']);
    }

    public function test_una_carrera_sin_postulantes_aparece_sin_ingresantes(): void
    {
        $this->import(['70000001' => [$this->sis, 10, 0]]);

        $this->assertSame(['admitted' => [], 'filled' => 0, 'tie' => false], $this->admitted()['PSI']);
    }

    public function test_reporte_de_ingresantes_en_json_pdf_y_excel(): void
    {
        $this->cycle->update(['min_admission_score' => 20]);
        $this->import([
            '70000001' => [$this->sis, 10, 0],
            '70000002' => [$this->psi, 9, 1],
        ]);

        $report = $this->actingAs($this->admin)->getJson("/api/admin/reports/ingresantes?exam_id={$this->exam->id}")->assertOk()->json();
        $this->assertSame('Ingresantes: Examen final', $report['title']);
        $this->assertSame(['Vacantes' => 5, 'Ingresantes' => 2, 'Puntaje mínimo' => 20], $report['summary']);
        $this->assertSame(['70000001', '70000002'], array_column(collect($report['tables'])->firstWhere('title', 'Ingresantes')['rows'], 'dni'));

        $pdf = $this->actingAs($this->admin)->get("/api/admin/reports/ingresantes?exam_id={$this->exam->id}&format=pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->actingAs($this->admin)->get("/api/admin/reports/ingresantes?exam_id={$this->exam->id}&format=xlsx")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $byCareer = $this->actingAs($this->admin)->getJson("/api/admin/reports/ingresantes?exam_id={$this->exam->id}&group=B")->json();
        $this->assertSame(['70000002'], array_column(collect($byCareer['tables'])->firstWhere('title', 'Ingresantes')['rows'], 'dni'));
    }

    public function test_el_reporte_exige_el_examen(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/reports/ingresantes')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exam_id' => 'Indica el examen (exam_id) del que quieres los ingresantes.']);
    }

    public function test_los_estudiantes_no_ven_los_ingresantes_de_otros(): void
    {
        $this->actingAs(User::factory()->student()->create())->getJson("/api/exams/{$this->exam->id}/admitted")->assertForbidden();
        $this->actingAs(User::factory()->coordinator()->create())->getJson("/api/exams/{$this->exam->id}/admitted")->assertOk();
    }
}
