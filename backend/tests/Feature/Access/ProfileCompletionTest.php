<?php

namespace Tests\Feature\Access;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Middleware profile.completed y marcado del perfil completo. RF06, RF09. */
class ProfileCompletionTest extends TestCase
{
    use RefreshDatabase;

    private function incompleteStudent(): User
    {
        return User::factory()->student()->create(['profile_completed' => false]);
    }

    public function test_sin_perfil_completo_no_se_puede_crear_matricula(): void
    {
        $this->actingAs($this->incompleteStudent())
            ->postJson('/api/enrollments', ['step' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 'profile_incomplete');
    }

    public function test_sin_perfil_completo_no_se_puede_editar_la_matricula(): void
    {
        $student = $this->incompleteStudent();
        $enrollment = Enrollment::factory()->for($student, 'student')->create();

        $this->actingAs($student)
            ->putJson("/api/enrollments/{$enrollment->id}", ['step' => 2])
            ->assertForbidden()
            ->assertJsonPath('code', 'profile_incomplete');
    }

    public function test_sin_perfil_completo_no_se_puede_registrar_un_pago(): void
    {
        $this->actingAs($this->incompleteStudent())
            ->postJson('/api/payments', ['amount' => 100])
            ->assertForbidden()
            ->assertJsonPath('code', 'profile_incomplete');
    }

    public function test_sin_perfil_completo_si_puede_ver_sus_matriculas(): void
    {
        $this->actingAs($this->incompleteStudent())
            ->getJson('/api/enrollments')
            ->assertOk();
    }

    public function test_completar_dni_y_telefono_marca_el_perfil_y_habilita_la_matricula(): void
    {
        $student = $this->incompleteStudent();

        $this->actingAs($student)
            ->putJson('/api/profile', ['name' => 'Ana Quispe', 'dni' => '71234567', 'phone' => '987654321'])
            ->assertOk()
            ->assertJsonPath('data.profile_completed', true);

        $this->assertTrue($student->fresh()->profile_completed);

        $this->actingAs($student->fresh())
            ->postJson('/api/enrollments', ['step' => 2, 'career_id' => Career::factory()->create()->id])
            ->assertCreated();
    }

    public function test_solo_con_dni_el_perfil_sigue_incompleto(): void
    {
        $student = $this->incompleteStudent();

        $this->actingAs($student)
            ->putJson('/api/profile', ['name' => 'Ana Quispe', 'dni' => '71234567'])
            ->assertOk()
            ->assertJsonPath('data.profile_completed', false);
    }

    public function test_el_middleware_no_aplica_al_personal(): void
    {
        // Un docente sin perfil completo recibe el rechazo de la policy,
        // no el del middleware: matricularse no es una acción de su rol.
        $teacher = User::factory()->teacher()->create(['profile_completed' => false]);

        $this->actingAs($teacher)
            ->postJson('/api/enrollments', ['step' => 1])
            ->assertForbidden()
            ->assertJsonMissingPath('code')
            ->assertJsonPath('message', 'Solo los estudiantes pueden solicitar matrícula.');
    }
}
