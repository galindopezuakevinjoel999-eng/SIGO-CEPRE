<?php

namespace Tests\Feature\Access;

use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** EnrollmentPolicy: quién ve, edita, elimina y aprueba matrículas. RF16, RNF11. */
class EnrollmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $studentA;

    private User $studentB;

    private Enrollment $enrollmentA;

    private Enrollment $enrollmentB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->studentA = User::factory()->student()->withCompletedProfile()->create();
        $this->studentB = User::factory()->student()->withCompletedProfile()->create();
        $this->enrollmentA = Enrollment::factory()->for($this->studentA, 'student')->create();
        $this->enrollmentB = Enrollment::factory()->for($this->studentB, 'student')->create();
    }

    // ---------- Ver ----------

    public function test_el_estudiante_a_no_puede_ver_la_matricula_de_b(): void
    {
        $this->actingAs($this->studentA)
            ->getJson("/api/enrollments/{$this->enrollmentB->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes acceso a esta matrícula.');
    }

    public function test_el_estudiante_ve_su_propia_matricula(): void
    {
        $this->actingAs($this->studentA)
            ->getJson("/api/enrollments/{$this->enrollmentA->id}")
            ->assertOk()
            ->assertJsonPath('id', $this->enrollmentA->id);
    }

    public function test_el_listado_del_estudiante_solo_trae_las_suyas(): void
    {
        $ids = $this->actingAs($this->studentA)
            ->getJson('/api/enrollments')
            ->assertOk()
            ->json('data.*.id');

        $this->assertSame([$this->enrollmentA->id], $ids);
    }

    public function test_admin_y_coordinador_ven_todas_las_matriculas(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->coordinator()->create()] as $staff) {
            $this->actingAs($staff)->getJson('/api/enrollments')->assertOk()->assertJsonCount(2, 'data');
            $this->actingAs($staff)->getJson("/api/enrollments/{$this->enrollmentB->id}")->assertOk();
        }
    }

    public function test_el_docente_no_ve_matriculas(): void
    {
        $teacher = User::factory()->teacher()->create();

        $this->actingAs($teacher)->getJson('/api/enrollments')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($teacher)->getJson("/api/enrollments/{$this->enrollmentA->id}")->assertForbidden();
    }

    // ---------- Editar y eliminar ----------

    public function test_el_estudiante_a_no_puede_editar_la_matricula_de_b(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/enrollments/{$this->enrollmentB->id}", ['step' => 3, 'documents' => ['dni.pdf']])
            ->assertForbidden();

        $this->assertSame(2, $this->enrollmentB->fresh()->current_step);
    }

    public function test_sin_permiso_responde_403_antes_que_errores_de_validacion(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/enrollments/{$this->enrollmentB->id}", ['step' => 99])
            ->assertForbidden();
    }

    public function test_el_estudiante_a_no_puede_eliminar_la_matricula_de_b(): void
    {
        $this->actingAs($this->studentA)
            ->deleteJson("/api/enrollments/{$this->enrollmentB->id}")
            ->assertForbidden();

        $this->assertModelExists($this->enrollmentB);
    }

    public function test_el_estudiante_elimina_su_matricula_pendiente(): void
    {
        $this->actingAs($this->studentA)
            ->deleteJson("/api/enrollments/{$this->enrollmentA->id}")
            ->assertOk();

        $this->assertModelMissing($this->enrollmentA);
    }

    public function test_no_se_edita_una_matricula_ya_aprobada(): void
    {
        $approved = Enrollment::factory()->approved()->for($this->studentA, 'student')->create();

        $this->actingAs($this->studentA)
            ->putJson("/api/enrollments/{$approved->id}", ['step' => 3, 'documents' => ['dni.pdf']])
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes editar una matrícula que ya fue procesada.');
    }

    public function test_no_se_elimina_una_matricula_ya_aprobada_ni_siendo_admin(): void
    {
        $approved = Enrollment::factory()->approved()->for($this->studentA, 'student')->create();

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson("/api/enrollments/{$approved->id}")
            ->assertForbidden();
    }

    public function test_el_estudiante_no_puede_cambiar_el_estado_editando(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/enrollments/{$this->enrollmentA->id}", ['step' => 2, 'status' => 'approved'])
            ->assertOk();

        $this->assertSame('pending', $this->enrollmentA->fresh()->status);
    }

    // ---------- Aprobar / rechazar ----------

    public function test_el_estudiante_no_puede_aprobar_su_matricula(): void
    {
        $this->actingAs($this->studentA)
            ->putJson("/api/enrollments/{$this->enrollmentA->id}/status", ['status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $this->enrollmentA->fresh()->status);
    }

    public function test_el_docente_no_puede_aprobar_matriculas(): void
    {
        $this->actingAs(User::factory()->teacher()->create())
            ->putJson("/api/enrollments/{$this->enrollmentA->id}/status", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_el_coordinador_aprueba_una_matricula(): void
    {
        $this->actingAs(User::factory()->coordinator()->create())
            ->putJson("/api/enrollments/{$this->enrollmentA->id}/status", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $this->assertNotNull($this->enrollmentA->fresh()->approved_at);
    }

    public function test_el_admin_rechaza_indicando_el_motivo(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->putJson("/api/enrollments/{$this->enrollmentA->id}/status", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rejection_reason');

        $this->actingAs($admin)
            ->putJson("/api/enrollments/{$this->enrollmentA->id}/status", [
                'status' => 'rejected',
                'rejection_reason' => 'DNI ilegible',
            ])
            ->assertOk()
            ->assertJsonPath('rejection_reason', 'DNI ilegible');
    }
}
