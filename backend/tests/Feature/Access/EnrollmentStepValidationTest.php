<?php

namespace Tests\Feature\Access;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Validación del formulario de matrícula por pasos (Form Requests). RF10–RF14. */
class EnrollmentStepValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->student = User::factory()->student()->withCompletedProfile()->create();
    }

    public function test_paso_1_no_exige_datos(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/enrollments', ['step' => 1])
            ->assertCreated()
            ->assertJsonPath('current_step', 1)
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('student_id', $this->student->id);
    }

    public function test_paso_2_exige_carrera(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/enrollments', ['step' => 2])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['career_id' => 'Selecciona una carrera.']);
    }

    public function test_la_carrera_debe_existir(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/enrollments', ['step' => 2, 'career_id' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('career_id');
    }

    public function test_paso_3_exige_documentos(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/enrollments', ['step' => 3, 'career_id' => Career::factory()->create()->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');
    }

    public function test_paso_4_valida_el_aula(): void
    {
        $this->actingAs($this->student)
            ->postJson('/api/enrollments', [
                'step' => 4,
                'career_id' => Career::factory()->create()->id,
                'documents' => [$this->fakePdf('dni.pdf')],
                'classroom_id' => 9999,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('classroom_id');
    }

    public function test_un_paso_fuera_de_rango_es_rechazado(): void
    {
        foreach ([0, 5, 'abc'] as $step) {
            $this->actingAs($this->student)
                ->postJson('/api/enrollments', ['step' => $step])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('step');
        }
    }

    public function test_el_estudiante_no_elige_el_dueno_ni_el_estado_al_crear(): void
    {
        $other = User::factory()->student()->create();

        $this->actingAs($this->student)
            ->postJson('/api/enrollments', ['step' => 1, 'student_id' => $other->id, 'status' => 'approved'])
            ->assertCreated()
            ->assertJsonPath('student_id', $this->student->id)
            ->assertJsonPath('status', 'pending');
    }

    public function test_al_avanzar_no_hace_falta_reenviar_lo_ya_guardado(): void
    {
        $enrollment = Enrollment::factory()->for($this->student, 'student')->create(['current_step' => 2]);

        $this->actingAs($this->student)
            ->putJson("/api/enrollments/{$enrollment->id}", ['step' => 3, 'documents' => [$this->fakePdf('dni.pdf')]])
            ->assertOk()
            ->assertJsonPath('current_step', 3)
            ->assertJsonPath('career_id', $enrollment->career_id);
    }

    public function test_al_avanzar_se_exige_lo_que_aun_falta(): void
    {
        $enrollment = Enrollment::factory()->for($this->student, 'student')->create(['current_step' => 2]);

        $this->actingAs($this->student)
            ->putJson("/api/enrollments/{$enrollment->id}", ['step' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('documents');
    }

    public function test_no_se_puede_borrar_un_dato_ya_guardado_con_null(): void
    {
        $enrollment = Enrollment::factory()->for($this->student, 'student')->create(['current_step' => 2]);

        $this->actingAs($this->student)
            ->putJson("/api/enrollments/{$enrollment->id}", ['step' => 2, 'career_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('career_id');
    }
}
