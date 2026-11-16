<?php

namespace Tests\Feature\Enrollments;

use App\Exceptions\EnrollmentCodeException;
use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Classroom;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Semester;
use App\Models\User;
use App\Services\EnrollmentCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Código de matrícula [GRUPO]-[CARRERA]-[AULA]-[SEMESTRE]-[CORRELATIVO].
 * Corre en SQLite, lo que demuestra que ya no depende de funciones de MySQL.
 */
class EnrollmentCodeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Career $sistemas;

    private Classroom $aula01;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->sistemas = Career::factory()->create(['code' => 'SIS', 'name' => 'Ingeniería de Sistemas', 'group' => 'A']);
        $this->aula01 = Classroom::factory()->create(['number' => '01']);
        Semester::factory()->active()->create(['code' => '262', 'year' => 2026, 'cycle' => 2, 'name' => '2026-II']);
    }

    /** Matrícula pendiente de Sistemas con un pago validado, lista para aprobar. */
    private function payableEnrollment(array $attributes = []): Enrollment
    {
        $enrollment = Enrollment::factory()->create(['career_id' => $this->sistemas->id, ...$attributes]);
        Payment::factory()->validated()->for($enrollment->student, 'student')->for($enrollment)->create();

        return $enrollment;
    }

    private function approve(Enrollment $enrollment, ?Classroom $classroom = null)
    {
        return $this->actingAs($this->admin)->putJson("/api/enrollments/{$enrollment->id}/status", [
            'status' => 'approved',
            'classroom_id' => ($classroom ?? $this->aula01)->id,
        ]);
    }

    public function test_formato_del_codigo(): void
    {
        $this->approve($this->payableEnrollment())
            ->assertOk()
            ->assertJsonPath('enrollment_code', 'A-SIS-01-262-001')
            ->assertJsonPath('group', 'A')
            ->assertJsonPath('semester.code', '262');
    }

    public function test_el_correlativo_avanza_dentro_del_mismo_grupo_carrera_aula_y_semestre(): void
    {
        $this->approve($this->payableEnrollment())->assertJsonPath('enrollment_code', 'A-SIS-01-262-001');
        $this->approve($this->payableEnrollment())->assertJsonPath('enrollment_code', 'A-SIS-01-262-002');
        $this->approve($this->payableEnrollment())->assertJsonPath('enrollment_code', 'A-SIS-01-262-003');
    }

    public function test_cada_aula_y_carrera_tiene_su_propio_correlativo(): void
    {
        $this->approve($this->payableEnrollment())->assertJsonPath('enrollment_code', 'A-SIS-01-262-001');

        $aula02 = Classroom::factory()->create(['number' => '02']);
        $this->approve($this->payableEnrollment(), $aula02)->assertJsonPath('enrollment_code', 'A-SIS-02-262-001');

        $psicologia = Career::factory()->create(['code' => 'PSI', 'group' => 'B']);
        $this->approve($this->payableEnrollment(['career_id' => $psicologia->id]))
            ->assertJsonPath('enrollment_code', 'B-PSI-01-262-001');
    }

    public function test_el_correlativo_usa_el_mayor_numerico_y_no_el_orden_alfabetico(): void
    {
        // Como texto, "999" > "1000"; el correlativo debe compararse como número.
        Enrollment::factory()->approved()->create(['enrollment_code' => 'A-SIS-01-262-999']);
        Enrollment::factory()->approved()->create(['enrollment_code' => 'A-SIS-01-262-1000']);
        Enrollment::factory()->approved()->create(['enrollment_code' => 'A-SIS-01-262-009']);

        $this->approve($this->payableEnrollment())->assertJsonPath('enrollment_code', 'A-SIS-01-262-1001');
    }

    public function test_el_codigo_queda_en_la_auditoria(): void
    {
        $this->approve($this->payableEnrollment())->assertOk();

        $this->assertSame('A-SIS-01-262-001', AuditLog::sole()->metadata['enrollment_code']);
    }

    public function test_sin_semestre_activo_no_se_aprueba_y_no_queda_nada_a_medias(): void
    {
        Semester::query()->update(['is_active' => false]);
        $enrollment = $this->payableEnrollment();

        $this->approve($enrollment)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'No se pudo aprobar la matrícula: No hay un semestre activo configurado.');

        $fresh = $enrollment->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->enrollment_code);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->classroom_id);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_sin_aula_se_aprueba_sin_codigo(): void
    {
        $enrollment = $this->payableEnrollment();

        $this->actingAs($this->admin)
            ->putJson("/api/enrollments/{$enrollment->id}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('enrollment_code', null);
    }

    public function test_el_servicio_exige_aula(): void
    {
        $enrollment = Enrollment::factory()->create(['career_id' => $this->sistemas->id]);

        $this->expectException(EnrollmentCodeException::class);
        DB::transaction(fn () => app(EnrollmentCodeService::class)->assign($enrollment));
    }
}
