<?php

namespace Tests\Feature\Academic;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regla del CEPRE: cada semestre es independiente, como si el estudiante fuera
 * nuevo. Solo cuentan las faltas del semestre activo; las anteriores quedan
 * como historial.
 */
class SemesterAttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teacher;

    private User $student;

    private Semester $semester2026II;

    private Schedule $schedule2026II;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->teacher = User::factory()->teacher()->create();
        $this->student = User::factory()->student()->create(['student_status' => 'activo']);

        $this->semester2026II = Semester::factory()->active()->create(['code' => '262', 'year' => 2026, 'cycle' => 2, 'name' => '2026-II']);
        $this->schedule2026II = Schedule::factory()->for($this->semester2026II)->create();
    }

    private function recordAbsence(Schedule $schedule, string $date)
    {
        return $this->actingAs($this->teacher)->postJson('/api/attendance', [
            'student_id' => $this->student->id,
            'schedule_id' => $schedule->id,
            'date' => $date,
            'status' => 'absent',
        ])->assertCreated();
    }

    /** 10 faltas en 2026-II: llega al máximo por defecto y queda inhabilitado. */
    private function disableIn2026II(): void
    {
        for ($day = 1; $day <= 10; $day++) {
            $this->recordAbsence($this->schedule2026II, "2026-09-{$day}");
        }

        $this->assertSame('inhabilitado', $this->student->fresh()->student_status);
        $this->assertSame(10, $this->student->fresh()->absence_count);
    }

    public function test_inhabilitado_en_2026_ii_empieza_2027_i_activo_y_con_0_faltas(): void
    {
        $this->disableIn2026II();

        $this->actingAs($this->admin)
            ->postJson('/api/semesters', ['code' => '271', 'year' => 2027, 'cycle' => 1, 'name' => '2027-I', 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('is_active', true);

        $student = $this->student->fresh();
        $this->assertSame('activo', $student->student_status);
        $this->assertSame(0, $student->absence_count);
        $this->assertSame('271', Semester::current()->code);
        $this->assertFalse($this->semester2026II->fresh()->is_active);
    }

    public function test_las_faltas_antiguas_se_conservan_como_historial(): void
    {
        $this->disableIn2026II();

        $semester2027I = Semester::factory()->create(['code' => '271', 'year' => 2027, 'cycle' => 1]);
        $this->actingAs($this->admin)
            ->putJson("/api/semesters/{$semester2027I->id}", ['is_active' => true])
            ->assertOk();

        $history = Attendance::where('student_id', $this->student->id)->with('schedule')->get();
        $this->assertCount(10, $history);
        $this->assertTrue($history->every(fn ($a) => $a->schedule->semester_id === $this->semester2026II->id));
    }

    public function test_en_el_nuevo_semestre_solo_cuentan_sus_propias_faltas(): void
    {
        $this->disableIn2026II();

        $semester2027I = Semester::factory()->create(['code' => '271', 'year' => 2027, 'cycle' => 1]);
        $semester2027I->activate();
        $schedule2027I = Schedule::factory()->for($semester2027I)->create();

        $this->recordAbsence($schedule2027I, '2027-04-05');

        $student = $this->student->fresh();
        $this->assertSame(1, $student->absence_count);
        $this->assertSame('activo', $student->student_status);
    }

    public function test_corregir_una_falta_antigua_no_afecta_el_semestre_actual(): void
    {
        $old = Semester::factory()->create(['code' => '261', 'year' => 2026, 'cycle' => 1]);
        $oldSchedule = Schedule::factory()->for($old)->create();
        // Fechas fijas (abril) para no chocar con la falta del 30 de mayo de abajo.
        Attendance::factory()->absent()->count(12)->for($this->student, 'student')->for($oldSchedule)
            ->sequence(fn ($s) => ['date' => sprintf('2026-04-%02d', $s->index + 1)])
            ->create();

        // Un registro en el horario antiguo dispara el recálculo, pero no cuenta.
        $this->recordAbsence($oldSchedule, '2026-05-30');

        $this->assertSame(0, $this->student->fresh()->absence_count);
        $this->assertSame('activo', $this->student->fresh()->student_status);
    }

    public function test_reactivar_el_semestre_en_curso_no_reinicia_las_faltas(): void
    {
        $this->disableIn2026II();

        $this->actingAs($this->admin)
            ->putJson("/api/semesters/{$this->semester2026II->id}", ['name' => '2026-II (CEPRE)', 'is_active' => true])
            ->assertOk()
            ->assertJsonPath('name', '2026-II (CEPRE)');

        $this->assertSame(10, $this->student->fresh()->absence_count);
        $this->assertSame('inhabilitado', $this->student->fresh()->student_status);
    }

    public function test_el_personal_no_se_ve_afectado_por_el_reinicio(): void
    {
        $this->teacher->update(['student_status' => 'en_riesgo', 'absence_count' => 3]);

        Semester::factory()->create(['code' => '271', 'year' => 2027, 'cycle' => 1])->activate();

        $this->assertSame('en_riesgo', $this->teacher->fresh()->student_status);
    }

    public function test_no_se_registra_dos_veces_la_misma_asistencia(): void
    {
        $this->recordAbsence($this->schedule2026II, '2026-09-01');

        $this->actingAs($this->teacher)
            ->postJson('/api/attendance', [
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule2026II->id,
                'date' => '2026-09-01',
                'status' => 'present',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'Ya se registró la asistencia de este estudiante en este horario y fecha.']);
    }

    public function test_toda_falta_debe_indicar_su_horario(): void
    {
        $this->actingAs($this->teacher)
            ->postJson('/api/attendance', ['student_id' => $this->student->id, 'date' => '2026-09-01', 'status' => 'absent'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule_id');
    }
}
