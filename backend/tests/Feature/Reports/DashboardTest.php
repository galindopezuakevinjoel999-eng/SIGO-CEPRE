<?php

namespace Tests\Feature\Reports;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

/** GET /api/admin/dashboard — valores esperados en BuildsReportScenario. */
class DashboardTest extends TestCase
{
    use BuildsReportScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReportScenario();
    }

    private function dashboard(string $query = ''): array
    {
        return $this->actingAs($this->admin)->getJson('/api/admin/dashboard'.$query)->assertOk()->json();
    }

    public function test_usa_el_semestre_activo_por_defecto(): void
    {
        $this->assertSame('262', $this->dashboard()['filters']['semester']['code']);
    }

    public function test_matriculas_por_estado(): void
    {
        $this->assertSame(['total' => 5, 'pending' => 1, 'approved' => 3, 'rejected' => 1], $this->dashboard()['enrollments']);
    }

    public function test_pagos_ingresos_y_deudores(): void
    {
        $payments = $this->dashboard()['payments'];

        $this->assertEquals(['count' => 2, 'amount' => 400], $payments['validated']);
        $this->assertEquals(['count' => 1, 'amount' => 50], $payments['pending']);
        $this->assertEquals(['count' => 2, 'amount' => 200], $payments['rejected']);
        $this->assertEquals(400, $payments['income']);
        // Luis 200 + Rosa 300 + Jhon 300 (Mia está rechazada y no debe nada).
        $this->assertEquals(['count' => 3, 'total_debt' => 800], $payments['debtors']);
    }

    public function test_sin_costo_configurado_no_calcula_deudores(): void
    {
        SystemSetting::where('key', PaymentService::COST_SETTING)->delete();

        $this->assertNull($this->dashboard()['payments']['debtors']);
    }

    public function test_promedio_del_ultimo_examen_publicado(): void
    {
        $last = $this->dashboard()['last_exam'];

        $this->assertSame('Simulacro 2', $last['exam']['name']); // el borrador posterior no cuenta
        $this->assertSame(3, $last['participants']);
        $this->assertEquals(26.667, $last['average_score']);   // (30 + 20 + 30) / 3
        $this->assertEquals(30, $last['max_score']);
        $this->assertEquals(20, $last['min_score']);
    }

    public function test_asistencia_y_estudiantes_en_riesgo(): void
    {
        $attendance = $this->dashboard()['attendance'];

        $this->assertSame(10, $attendance['records']);   // las 5 faltas del semestre anterior no cuentan
        $this->assertSame(4, $attendance['attended']);   // Ana 3 (incluye tardanza) + Luis 1
        $this->assertSame(6, $attendance['absent']);
        $this->assertEquals(40.0, $attendance['rate']);
        $this->assertSame(1, $attendance['students_at_risk']);   // Luis
        $this->assertSame(1, $attendance['students_disabled']);  // Rosa
    }

    public function test_matriculas_por_carrera_y_por_grupo(): void
    {
        $data = $this->dashboard();

        // De más a menos solicitudes (SIS y ADM tienen 2; PSI, 1).
        $byCareer = collect($data['enrollments_by_career'])->mapWithKeys(fn ($r) => [$r['career']['code'] => [$r['total'], $r['approved']]])->all();
        $this->assertSame(['SIS' => [2, 2], 'ADM' => [2, 0], 'PSI' => [1, 1]], $byCareer);

        $byGroup = collect($data['enrollments_by_group'])->mapWithKeys(fn ($r) => [$r['group'] => [$r['total'], $r['approved']]])->all();
        $this->assertSame(['A' => [2, 2], 'B' => [1, 1], 'C' => [2, 0]], $byGroup);
    }

    public function test_el_semestre_anterior_tiene_sus_propios_numeros(): void
    {
        $data = $this->dashboard("?semester_id={$this->previousSemester->id}");

        $this->assertSame(['total' => 1, 'pending' => 0, 'approved' => 1, 'rejected' => 0], $data['enrollments']);
        $this->assertEquals(999, $data['payments']['income']);
        $this->assertSame(5, $data['attendance']['absent']);
        $this->assertNull($data['last_exam']);
    }

    public function test_solo_administracion_y_coordinacion(): void
    {
        $this->actingAs($this->coordinator)->getJson('/api/admin/dashboard')->assertOk();

        foreach ([User::factory()->teacher()->create(), $this->students['ana']] as $user) {
            $this->actingAs($user)->getJson('/api/admin/dashboard')->assertForbidden();
        }
    }
}
