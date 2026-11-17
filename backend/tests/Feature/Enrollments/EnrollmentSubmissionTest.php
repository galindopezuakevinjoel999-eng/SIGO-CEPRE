<?php

namespace Tests\Feature\Enrollments;

use App\Models\Career;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** submitted_at: se fija al confirmar el paso 4 por primera vez (indicador D1). */
class EnrollmentSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openEnrollmentCycle();

        $this->student = User::factory()->student()->withCompletedProfile()->create();
        $this->enrollment = Enrollment::factory()->for($this->student, 'student')->create(['current_step' => 2]);
    }

    private function step(int $step, array $data = [])
    {
        return $this->actingAs($this->student)->putJson("/api/enrollments/{$this->enrollment->id}", ['step' => $step, ...$data]);
    }

    public function test_los_pasos_1_a_3_no_envian_la_matricula(): void
    {
        $this->step(2)->assertOk();
        $this->step(3, ['documents' => $this->requiredDocuments()])->assertOk();

        $this->assertNull($this->enrollment->fresh()->submitted_at);
    }

    public function test_confirmar_el_paso_4_registra_el_envio(): void
    {
        $this->step(3, ['documents' => $this->requiredDocuments()])->assertOk();

        $this->travelTo(Carbon::parse('2026-08-01 14:00:00'));
        $this->step(4)->assertOk();

        $this->assertSame('2026-08-01 14:00:00', $this->enrollment->fresh()->submitted_at->format('Y-m-d H:i:s'));
    }

    public function test_corregir_despues_no_cambia_la_fecha_del_primer_envio(): void
    {
        $this->step(3, ['documents' => $this->requiredDocuments()])->assertOk();
        $this->travelTo(Carbon::parse('2026-08-01 14:00:00'));
        $this->step(4)->assertOk();

        // Vuelve a subir documentos y a confirmar al día siguiente (sigue pendiente).
        $this->travelTo(Carbon::parse('2026-08-02 09:00:00'));
        $this->step(3, ['documents' => ['dni' => $this->fakePdf('dni-corregido.pdf')]])->assertOk();
        $this->step(4)->assertOk();

        $this->assertSame('2026-08-01 14:00:00', $this->enrollment->fresh()->submitted_at->format('Y-m-d H:i:s'));
    }

    public function test_crear_la_matricula_directamente_en_el_paso_4_la_envia(): void
    {
        $other = User::factory()->student()->withCompletedProfile()->create();

        $id = $this->actingAs($other)->postJson('/api/enrollments', [
            'step' => 4,
            'career_id' => Career::factory()->create()->id,
            'documents' => $this->requiredDocuments(),
        ])->assertCreated()->json('id');

        $this->assertNotNull(Enrollment::find($id)->submitted_at);
    }

    public function test_el_estudiante_no_puede_fijar_la_fecha_de_envio(): void
    {
        $this->step(2, ['submitted_at' => '2020-01-01 00:00:00'])->assertOk();

        $this->assertNull($this->enrollment->fresh()->submitted_at);
    }
}
