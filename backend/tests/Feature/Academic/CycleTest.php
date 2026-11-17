<?php

namespace Tests\Feature\Academic;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Ciclos del CEPRE (intensivo / ordinario I / ordinario II), inscripción y vacantes. */
class CycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    // ---------- Códigos y nombres ----------

    public function test_codigo_y_nombre_se_derivan_del_tipo_y_el_anio(): void
    {
        foreach ([['intensivo', '260', 'Ciclo Intensivo 2026'], ['ordinario_i', '261', 'Ciclo Ordinario I 2026'], ['ordinario_ii', '262', 'Ciclo Ordinario II 2026']] as [$type, $code, $name]) {
            $this->actingAs($this->admin)->postJson('/api/semesters', ['type' => $type, 'year' => 2026])
                ->assertCreated()
                ->assertJsonPath('code', $code)
                ->assertJsonPath('name', $name)
                ->assertJsonPath('cycle', (int) substr($code, -1));
        }
    }

    public function test_no_se_repite_un_ciclo(): void
    {
        $this->actingAs($this->admin)->postJson('/api/semesters', ['type' => 'ordinario_ii', 'year' => 2026])->assertCreated();

        $this->actingAs($this->admin)->postJson('/api/semesters', ['type' => 'ordinario_ii', 'year' => 2026])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type' => 'Ya existe el Ciclo Ordinario II 2026 (código 262).']);
    }

    public function test_tipo_desconocido_y_fechas_invertidas(): void
    {
        $this->actingAs($this->admin)->postJson('/api/semesters', ['type' => 'verano', 'year' => 2026])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->actingAs($this->admin)->postJson('/api/semesters', [
            'type' => 'intensivo', 'year' => 2027, 'enrollment_start' => '2027-01-20', 'enrollment_end' => '2027-01-10',
        ])->assertUnprocessable()->assertJsonValidationErrors(['enrollment_end' => 'El fin de la inscripción no puede ser anterior a su inicio.']);
    }

    public function test_modalidades_de_pago_con_cuotas_desiguales(): void
    {
        $this->actingAs($this->admin)->postJson('/api/semesters', [
            'type' => 'ordinario_ii', 'year' => 2026,
            'payment_plans' => [
                'unico' => ['label' => 'Pago único', 'installments' => [600]],
                'fraccionado' => ['label' => 'Pago fraccionado', 'installments' => [500, 150]],
            ],
        ])->assertCreated()->assertJsonPath('payment_plans.fraccionado.installments', [500, 150]);

        $this->actingAs($this->admin)->postJson('/api/semesters', [
            'type' => 'intensivo', 'year' => 2026, 'payment_plans' => ['Pago Único' => ['label' => 'x', 'installments' => [0]]],
        ])->assertUnprocessable()->assertJsonValidationErrors('payment_plans.Pago Único.installments.0');
    }

    public function test_solo_el_admin_gestiona_ciclos(): void
    {
        $this->actingAs(User::factory()->coordinator()->create())
            ->postJson('/api/semesters', ['type' => 'intensivo', 'year' => 2026])
            ->assertForbidden();
    }

    // ---------- Periodo de inscripción ----------

    public function test_la_inscripcion_esta_abierta_entre_ambas_fechas_incluidas(): void
    {
        $cycle = Semester::factory()->create(['enrollment_start' => '2026-08-03', 'enrollment_end' => '2026-08-21']);
        $lima = fn (string $at) => Carbon::parse($at, 'America/Lima');

        $this->assertFalse($cycle->isEnrollmentOpen($lima('2026-08-02 23:59')));
        $this->assertTrue($cycle->isEnrollmentOpen($lima('2026-08-03 00:00')));
        $this->assertTrue($cycle->isEnrollmentOpen($lima('2026-08-21 23:59')));
        $this->assertFalse($cycle->isEnrollmentOpen($lima('2026-08-22 00:00')));

        // 21/08 a las 22:00 en Lima ya es 22/08 en UTC: se usa la fecha de Lima.
        $this->assertTrue($cycle->isEnrollmentOpen(Carbon::parse('2026-08-22 03:00', 'UTC')));
    }

    public function test_sin_fechas_la_inscripcion_esta_cerrada(): void
    {
        $this->assertFalse(Semester::factory()->create()->isEnrollmentOpen());
    }

    private function student(): User
    {
        return User::factory()->student()->withCompletedProfile()->create();
    }

    public function test_solo_se_crea_la_matricula_dentro_del_periodo(): void
    {
        Semester::factory()->active()->create(['name' => 'Ciclo Ordinario II 2026', 'enrollment_start' => '2026-08-03', 'enrollment_end' => '2026-08-21']);

        $this->travelTo(Carbon::parse('2026-08-25 10:00', 'America/Lima'));
        $this->actingAs($this->student())->postJson('/api/enrollments', ['step' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['semester' => 'La inscripción para el Ciclo Ordinario II 2026 es del 03/08/2026 al 21/08/2026.']);

        $this->travelTo(Carbon::parse('2026-08-10 10:00', 'America/Lima'));
        $this->actingAs($this->student())->postJson('/api/enrollments', ['step' => 1])->assertCreated();
    }

    public function test_sin_ciclo_activo_no_hay_inscripcion(): void
    {
        $this->actingAs($this->student())->postJson('/api/enrollments', ['step' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['semester' => 'No hay un ciclo activo: la inscripción no está abierta.']);
    }

    public function test_quien_empezo_a_tiempo_puede_completar_los_pasos_despues(): void
    {
        $cycle = Semester::factory()->active()->create(['enrollment_start' => '2026-08-03', 'enrollment_end' => '2026-08-21']);
        $student = $this->student();
        $enrollment = Enrollment::factory()->for($student, 'student')->for($cycle)->create(['current_step' => 1]);

        $this->travelTo(Carbon::parse('2026-08-25 10:00', 'America/Lima'));

        $this->actingAs($student)->putJson("/api/enrollments/{$enrollment->id}", ['step' => 2, 'career_id' => Career::factory()->create()->id])
            ->assertOk();
    }

    // ---------- Vacantes y puntaje mínimo ----------

    public function test_el_admin_configura_vacantes_por_carrera(): void
    {
        $cycle = Semester::factory()->create();
        [$sis, $psi] = [Career::factory()->create(['code' => 'SIS']), Career::factory()->create(['code' => 'PSI'])];

        $this->actingAs($this->admin)->putJson("/api/semesters/{$cycle->id}/vacancies", ['vacancies' => [
            ['career_id' => $sis->id, 'vacancies' => 3],
            ['career_id' => $psi->id, 'vacancies' => 2],
        ]])->assertOk()->assertJsonCount(2);

        // Cambiar una carrera no borra las demás.
        $this->actingAs($this->admin)->putJson("/api/semesters/{$cycle->id}/vacancies", ['vacancies' => [['career_id' => $psi->id, 'vacancies' => 4]]])->assertOk();

        $this->assertSame(['PSI' => 4, 'SIS' => 3], $cycle->careers()->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->code => $c->pivot->vacancies])->all());
    }

    public function test_vacantes_invalidas_y_permisos(): void
    {
        $cycle = Semester::factory()->create();
        $career = Career::factory()->create();

        $this->actingAs($this->admin)->putJson("/api/semesters/{$cycle->id}/vacancies", ['vacancies' => [['career_id' => $career->id, 'vacancies' => -1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('vacancies.0.vacancies');

        $this->actingAs(User::factory()->coordinator()->create())
            ->putJson("/api/semesters/{$cycle->id}/vacancies", ['vacancies' => [['career_id' => $career->id, 'vacancies' => 3]]])
            ->assertForbidden();
    }

    public function test_puntaje_minimo_configurable_por_ciclo(): void
    {
        $cycle = Semester::factory()->create(['type' => 'ordinario_ii', 'year' => 2031, 'code' => '312']);

        $this->actingAs($this->admin)->putJson("/api/semesters/{$cycle->id}", ['min_admission_score' => 45.5])
            ->assertOk()
            ->assertJsonPath('min_admission_score', 45.5);
    }
}
