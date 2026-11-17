<?php

namespace Tests\Feature\Exams;

use App\Models\AuditLog;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Semester;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Crear, editar, publicar y eliminar exámenes (RF30, RF33). */
class ExamManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Semester $semester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->semester = Semester::factory()->active()->create();
    }

    private function examPayload(array $overrides = []): array
    {
        return [
            'name' => 'Primer simulacro',
            'type' => 'simulacro',
            'date' => '2026-10-04',
            'total_questions' => 100,
            'points_correct' => 4,
            'points_wrong' => -1,
            'points_blank' => 0,
            ...$overrides,
        ];
    }

    // ---------- Crear ----------

    public function test_el_admin_crea_un_examen_en_borrador_del_semestre_activo(): void
    {
        $this->actingAs($this->admin)->postJson('/api/exams', $this->examPayload(['group' => 'B']))
            ->assertCreated()
            ->assertJsonPath('status', Exam::STATUS_DRAFT)
            ->assertJsonPath('semester_id', $this->semester->id)
            ->assertJsonPath('group', 'B')
            ->assertJsonPath('points_wrong', -1);
    }

    public function test_sin_puntos_ni_valores_por_defecto_es_obligatorio_indicarlos(): void
    {
        $payload = $this->examPayload();
        unset($payload['points_correct'], $payload['points_wrong'], $payload['points_blank']);

        $this->actingAs($this->admin)->postJson('/api/exams', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'points_correct' => 'Indica el puntaje (points_correct): no hay un valor por defecto configurado (exam_points_correct).',
                'points_wrong',
                'points_blank',
            ]);
    }

    public function test_usa_los_puntos_por_defecto_de_system_settings(): void
    {
        SystemSetting::setValue('exam_points_correct', '20', 'decimal');
        SystemSetting::setValue('exam_points_wrong', '-1.125', 'decimal');
        SystemSetting::setValue('exam_points_blank', '0', 'decimal');

        $payload = $this->examPayload();
        unset($payload['points_correct'], $payload['points_wrong'], $payload['points_blank']);

        $this->actingAs($this->admin)->postJson('/api/exams', $payload)
            ->assertCreated()
            ->assertJsonPath('points_correct', 20)
            ->assertJsonPath('points_wrong', -1.125)
            ->assertJsonPath('points_blank', 0);
    }

    public function test_valida_tipo_y_grupo(): void
    {
        $this->actingAs($this->admin)->postJson('/api/exams', $this->examPayload(['type' => 'quiz', 'group' => 'D']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'group']);
    }

    public function test_solo_el_admin_crea_exámenes(): void
    {
        foreach ([User::factory()->coordinator()->create(), User::factory()->teacher()->create(), User::factory()->student()->create()] as $user) {
            $this->actingAs($user)->postJson('/api/exams', $this->examPayload())->assertForbidden();
        }
    }

    // ---------- Publicar ----------

    public function test_publicar_exige_resultados_importados(): void
    {
        $exam = Exam::factory()->for($this->semester)->create();

        $this->actingAs($this->admin)->putJson("/api/exams/{$exam->id}/publish")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Importa los resultados antes de publicar el examen.');
    }

    public function test_el_admin_publica_y_queda_en_audit_logs(): void
    {
        $exam = Exam::factory()->for($this->semester)->create();
        ExamResult::factory()->count(3)->for($exam)->create();

        $this->actingAs($this->admin)->putJson("/api/exams/{$exam->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', Exam::STATUS_PUBLISHED)
            ->assertJsonPath('published_by', $this->admin->id);

        $this->assertNotNull($exam->fresh()->published_at);

        $log = AuditLog::sole();
        $this->assertSame(AuditLogger::EXAM_PUBLISHED, $log->action);
        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertSame(['exam_id' => $exam->id, 'exam_name' => $exam->name, 'results' => 3], $log->metadata);
    }

    public function test_solo_el_admin_publica_y_una_sola_vez(): void
    {
        $exam = Exam::factory()->for($this->semester)->create();
        ExamResult::factory()->for($exam)->create();

        $this->actingAs(User::factory()->coordinator()->create())->putJson("/api/exams/{$exam->id}/publish")
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo administración puede publicar resultados.');

        $this->actingAs($this->admin)->putJson("/api/exams/{$exam->id}/publish")->assertOk();
        $this->actingAs($this->admin)->putJson("/api/exams/{$exam->id}/publish")
            ->assertForbidden()
            ->assertJsonPath('message', 'Los resultados de este examen ya están publicados.');

        $this->assertSame(1, AuditLog::count());
    }

    // ---------- Un examen publicado queda fijo ----------

    public function test_un_examen_publicado_no_se_edita_ni_elimina(): void
    {
        $exam = Exam::factory()->published()->for($this->semester)->create();

        $this->actingAs($this->admin)->putJson("/api/exams/{$exam->id}", ['points_correct' => 10])->assertForbidden();
        $this->actingAs($this->admin)->deleteJson("/api/exams/{$exam->id}")->assertForbidden();

        $this->assertModelExists($exam);
    }

    public function test_eliminar_un_borrador_borra_sus_resultados(): void
    {
        $exam = Exam::factory()->for($this->semester)->create();
        ExamResult::factory()->count(2)->for($exam)->create();

        $this->actingAs($this->admin)->deleteJson("/api/exams/{$exam->id}")->assertOk();

        $this->assertSame(0, ExamResult::count());
    }

    // ---------- Listado ----------

    public function test_admin_y_coordinador_listan_exámenes_los_demás_no(): void
    {
        Exam::factory()->for($this->semester)->create();
        Exam::factory()->published()->for($this->semester)->create();

        $this->actingAs($this->admin)->getJson('/api/exams')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs(User::factory()->coordinator()->create())->getJson('/api/exams?status=published')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs(User::factory()->student()->create())->getJson('/api/exams')->assertForbidden();
        $this->actingAs(User::factory()->teacher()->create())->getJson('/api/exams')->assertForbidden();
    }
}
