<?php

namespace Tests\Feature\Exams;

use App\Models\AuditLog;
use App\Models\Career;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Semester;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Importación del CSV de resultados (RF31, RF32).
 * Examen de prueba: 10 preguntas, +4 correcta, -1 incorrecta, 0 en blanco.
 */
class ExamImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Semester $semester;

    private Exam $exam;

    /** @var array<string, Career> */
    private array $careers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->semester = Semester::factory()->active()->create();
        $this->exam = Exam::factory()->for($this->semester)->create();
        $this->careers = [
            'A' => Career::factory()->create(['code' => 'SIS', 'group' => 'A']),
            'B' => Career::factory()->create(['code' => 'PSI', 'group' => 'B']),
        ];
    }

    /** Estudiante con matrícula aprobada en el semestre del examen. */
    private function student(string $dni, string $group = 'A', ?Semester $semester = null): User
    {
        $student = User::factory()->student()->create(['dni' => $dni]);
        Enrollment::factory()->approved()->for($student, 'student')->create([
            'career_id' => $this->careers[$group]->id,
            'semester_id' => ($semester ?? $this->semester)->id,
        ]);

        return $student;
    }

    private function csv(string $content, string $name = 'resultados.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function import(string $content, ?Exam $exam = null)
    {
        return $this->actingAs($this->admin)->postJson('/api/exams/'.($exam ?? $this->exam)->id.'/import', ['file' => $this->csv($content)]);
    }

    // ---------- CSV válido ----------

    public function test_importa_un_csv_valido_y_calcula_el_puntaje(): void
    {
        $ana = $this->student('71234561');
        $luis = $this->student('72345672', 'B');

        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n72345672,5,5,0\n")
            ->assertOk()
            ->assertJsonPath('message', 'Se importaron 2 resultados.')
            ->assertJsonPath('report.total_rows', 2)
            ->assertJsonPath('report.imported', 2)
            ->assertJsonPath('report.not_found', [])
            ->assertJsonPath('report.errors', []);

        $resultAna = ExamResult::where('student_id', $ana->id)->sole();
        $this->assertSame(31.0, $resultAna->score);        // 8×4 + 1×(-1) + 1×0
        $this->assertSame([8, 1, 1], [$resultAna->correct, $resultAna->wrong, $resultAna->blank]);
        $this->assertSame('A', $resultAna->group);
        $this->assertSame(15.0, ExamResult::where('student_id', $luis->id)->value('score')); // 5×4 − 5
        $this->assertNotNull($this->exam->fresh()->results_imported_at);
    }

    public function test_acepta_punto_y_coma_bom_mayusculas_y_lineas_vacias(): void
    {
        $this->student('71234561');

        // Así suele guardar Excel en español.
        $this->import("\xEF\xBB\xBFDNI;Correctas;Incorrectas;En_Blanco\r\n\r\n71234561;10;0;0\r\n")
            ->assertOk()
            ->assertJsonPath('report.imported', 1);

        $this->assertSame(40.0, ExamResult::sole()->score);
    }

    public function test_las_columnas_pueden_venir_en_otro_orden(): void
    {
        $this->student('71234561');

        $this->import("en_blanco,dni,incorrectas,correctas,observacion\n0,71234561,2,8,ok\n")->assertOk();

        $this->assertSame([8, 2, 0], [ExamResult::sole()->correct, ExamResult::sole()->wrong, ExamResult::sole()->blank]);
    }

    // ---------- DNI inexistente ----------

    public function test_informa_los_dni_no_encontrados(): void
    {
        $this->student('71234561');
        User::factory()->teacher()->create(['dni' => '79999999']); // existe, pero no es estudiante

        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n70000000,5,5,0\n79999999,5,5,0\n")
            ->assertOk()
            ->assertJsonPath('report.imported', 1)
            ->assertJsonPath('report.not_found', [
                ['line' => 3, 'dni' => '70000000'],
                ['line' => 4, 'dni' => '79999999'],
            ]);
    }

    // ---------- Filas inválidas ----------

    public function test_informa_las_filas_con_errores_y_importa_las_validas(): void
    {
        $this->student('71234561');
        $this->student('72345672');

        $report = $this->import(implode("\n", [
            'dni,correctas,incorrectas,en_blanco',
            '71234561,8,1,1',     // 2: válida
            '1234,8,1,1',         // 3: DNI corto
            '72345672,ocho,1,1',  // 4: no numérico
            '72345672,-1,1,10',   // 5: negativo
            '72345672,5,5,5',     // 6: suma 15 ≠ 10
            '72345672,,5,5',      // 7: vacío
            '71234561,7,2,1',     // 8: DNI repetido
        ]))->assertOk()->json('report');

        $this->assertSame(1, $report['imported']);
        $this->assertSame([3, 4, 5, 6, 7, 8], array_column($report['errors'], 'line'));

        $errors = array_column($report['errors'], 'errors', 'line');
        $this->assertSame(['DNI inválido: debe tener 8 dígitos.'], $errors[3]);
        $this->assertSame(['"correctas" debe ser un número entero mayor o igual a 0.'], $errors[4]);
        $this->assertSame(['La suma de respuestas (15) no coincide con el total de preguntas (10).'], $errors[6]);
        $this->assertSame(['DNI repetido en el archivo (ya aparece en la línea 2).'], $errors[8]);
    }

    public function test_sin_matricula_aprobada_en_el_semestre_es_un_error(): void
    {
        $this->student('71234561', 'A', Semester::factory()->create()); // otro semestre
        $pending = User::factory()->student()->create(['dni' => '72345672']);
        Enrollment::factory()->for($pending, 'student')->create(['semester_id' => $this->semester->id]); // pendiente

        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n72345672,8,1,1\n")
            ->assertUnprocessable()
            ->assertJsonPath('report.errors.0.errors.0', 'El estudiante no tiene matrícula aprobada en el semestre del examen.')
            ->assertJsonCount(2, 'report.errors');
    }

    public function test_un_examen_de_un_grupo_rechaza_estudiantes_de_otro(): void
    {
        $exam = Exam::factory()->for($this->semester)->create(['group' => 'A']);
        $this->student('71234561', 'A');
        $this->student('72345672', 'B');

        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n72345672,8,1,1\n", $exam)
            ->assertOk()
            ->assertJsonPath('report.imported', 1)
            ->assertJsonPath('report.errors.0.errors.0', 'El estudiante es del grupo B; el examen es solo para el grupo A.');
    }

    public function test_un_archivo_sin_las_columnas_de_la_plantilla_es_rechazado(): void
    {
        $this->import("documento,buenas,malas\n71234561,8,1\n")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Faltan columnas en el encabezado: dni, correctas, incorrectas, en_blanco. Usa la plantilla: dni,correctas,incorrectas,en_blanco.');

        $this->import('')->assertUnprocessable();
    }

    public function test_solo_acepta_archivos_csv(): void
    {
        $this->actingAs($this->admin)
            ->postJson("/api/exams/{$this->exam->id}/import", ['file' => $this->fakePdf('resultados.pdf')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    // ---------- Reimportación ----------

    public function test_reimportar_reemplaza_los_resultados(): void
    {
        $this->student('71234561');
        $this->student('72345672');

        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n72345672,5,5,0\n")->assertOk();
        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,9,1,0\n")->assertOk();

        $this->assertSame(1, ExamResult::count());
        $this->assertSame(35.0, ExamResult::sole()->score);
    }

    public function test_si_ninguna_fila_es_valida_conserva_los_resultados_anteriores(): void
    {
        $this->student('71234561');
        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n")->assertOk();

        $this->import("dni,correctas,incorrectas,en_blanco\n70000000,8,1,1\nabc,1,1,1\n")
            ->assertUnprocessable()
            ->assertJsonPath('report.imported', 0)
            ->assertJsonPath('message', 'No se importó ningún resultado. Revisa el informe; los resultados anteriores se conservan.');

        $this->assertSame(1, ExamResult::count());
    }

    public function test_no_se_importa_en_un_examen_publicado(): void
    {
        $exam = Exam::factory()->published()->for($this->semester)->create();

        $this->import("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n", $exam)
            ->assertForbidden()
            ->assertJsonPath('message', 'El examen ya está publicado: sus resultados no se pueden modificar.');
    }

    // ---------- Permisos, plantilla y auditoría ----------

    public function test_solo_el_admin_importa(): void
    {
        $this->student('71234561');

        foreach ([User::factory()->coordinator()->create(), User::factory()->teacher()->create(), User::factory()->student()->create()] as $user) {
            $this->actingAs($user)
                ->postJson("/api/exams/{$this->exam->id}/import", ['file' => $this->csv("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n")])
                ->assertForbidden();
        }

        $this->assertSame(0, ExamResult::count());
    }

    public function test_descarga_la_plantilla_vacia(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/exams/import-template')->assertOk()->assertDownload('plantilla-resultados.csv');

        $this->assertSame("dni,correctas,incorrectas,en_blanco\n", $response->getContent());
        $this->actingAs(User::factory()->student()->create())->getJson('/api/exams/import-template')->assertForbidden();
    }

    public function test_la_importacion_queda_en_audit_logs(): void
    {
        $this->student('71234561');

        $this->actingAs($this->admin)->postJson("/api/exams/{$this->exam->id}/import", [
            // 70000000: fila válida, DNI que no existe.
            'file' => $this->csv("dni,correctas,incorrectas,en_blanco\n71234561,8,1,1\n70000000,5,5,0\n", 'simulacro-1.csv'),
        ])->assertOk();

        $log = AuditLog::sole();
        $this->assertSame(AuditLogger::EXAM_RESULTS_IMPORTED, $log->action);
        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertNull($log->user_id);
        $this->assertSame([
            'exam_id' => $this->exam->id,
            'exam_name' => $this->exam->name,
            'file' => 'simulacro-1.csv',
            'total_rows' => 2,
            'imported' => 1,
            'not_found' => 1,
            'errors' => 0,
            'replaced_results' => 0,
        ], $log->metadata);
    }

    public function test_una_importacion_fallida_no_se_audita(): void
    {
        $this->import("dni,correctas,incorrectas,en_blanco\n70000000,8,1,1\n")->assertUnprocessable();

        $this->assertSame(0, AuditLog::count());
    }
}
