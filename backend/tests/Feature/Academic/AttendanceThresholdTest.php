<?php

namespace Tests\Feature\Academic;

use App\Models\Attendance;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Umbrales de faltas desde system_settings (absence_warning_1, max_absences). */
class AttendanceThresholdTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->teacher()->create();
        $this->student = User::factory()->student()->create(['student_status' => 'activo']);
    }

    private function recordAbsence(int $day)
    {
        return $this->actingAs($this->teacher)->postJson('/api/attendance', [
            'student_id' => $this->student->id,
            'date' => now()->subDays($day)->toDateString(),
            'status' => 'absent',
        ])->assertCreated();
    }

    private function statusAfterAbsences(int $count): string
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->recordAbsence($i);
        }

        return $this->student->fresh()->student_status;
    }

    public function test_usa_los_umbrales_configurados(): void
    {
        SystemSetting::setValue('absence_warning_1', '2', 'integer');
        SystemSetting::setValue('max_absences', '3', 'integer');

        $this->recordAbsence(1);
        $this->assertSame('activo', $this->student->fresh()->student_status);

        $this->recordAbsence(2);
        $this->assertSame('en_riesgo', $this->student->fresh()->student_status);

        $this->recordAbsence(3);
        $fresh = $this->student->fresh();
        $this->assertSame('inhabilitado', $fresh->student_status);
        $this->assertSame(3, $fresh->absence_count);
    }

    public function test_sin_configuracion_usa_5_y_10_por_defecto(): void
    {
        $this->assertSame('activo', $this->statusAfterAbsences(4));
        $this->recordAbsence(5);
        $this->assertSame('en_riesgo', $this->student->fresh()->student_status);
    }

    public function test_un_umbral_mas_alto_mantiene_activo_al_estudiante(): void
    {
        SystemSetting::setValue('absence_warning_1', '8', 'integer');

        $this->assertSame('activo', $this->statusAfterAbsences(6));
    }

    public function test_al_eliminar_faltas_se_recalcula_el_estatus(): void
    {
        SystemSetting::setValue('absence_warning_1', '2', 'integer');
        $this->statusAfterAbsences(2);

        $attendance = Attendance::where('student_id', $this->student->id)->first();
        $this->actingAs($this->teacher)->deleteJson("/api/attendance/{$attendance->id}")->assertOk();

        $this->assertSame('activo', $this->student->fresh()->student_status);
    }

    public function test_el_cambio_de_estatus_se_notifica(): void
    {
        SystemSetting::setValue('absence_warning_1', '1', 'integer');

        $this->recordAbsence(1);

        $this->assertDatabaseHas('notifications_system', [
            'user_id' => $this->student->id,
            'type' => 'attendance_status',
            'title' => 'Tu estatus académico ha cambiado',
        ]);
    }
}
