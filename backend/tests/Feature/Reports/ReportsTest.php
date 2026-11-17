<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Reports\Support\ReportDocument;
use App\Reports\Support\ReportTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

/** GET /api/admin/reports/{tipo} — valores esperados en BuildsReportScenario. */
class ReportsTest extends TestCase
{
    use BuildsReportScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReportScenario();
    }

    private function report(string $type, string $query = ''): array
    {
        return $this->actingAs($this->admin)->getJson("/api/admin/reports/{$type}{$query}")->assertOk()->json();
    }

    private function table(array $report, string $title): array
    {
        return collect($report['tables'])->firstWhere('title', $title)['rows'];
    }

    // ---------- Matrícula ----------

    public function test_matricula_lista_los_aprobados_con_su_codigo(): void
    {
        $report = $this->report('matricula');

        $this->assertSame('CEPRE UNAJMA', $report['institution']);
        $this->assertSame('2026-II', $report['filters']['Semestre']);
        $this->assertSame(5, $report['summary']['Solicitudes de matrícula']);
        $this->assertSame(3, $report['summary']['Matriculados (aprobados)']);
        $this->assertSame(2, $report['summary']['Grupo A (Ingenierías)']);

        $rows = $this->table($report, 'Matriculados');
        $this->assertSame(['A-SIS-01-262-001', 'A-SIS-01-262-002', 'B-PSI-02-262-001'], array_column($rows, 'code'));
        $this->assertSame(['Ana Quispe', '71000001', 'Ingeniería de Sistemas', 'A', '01'], [$rows[0]['student'], $rows[0]['dni'], $rows[0]['career'], $rows[0]['group'], $rows[0]['classroom']]);

        $this->assertSame([['classroom' => '01', 'total' => 2], ['classroom' => '02', 'total' => 1]], $this->table($report, 'Matriculados por aula'));
    }

    public function test_matricula_filtra_por_carrera_grupo_y_aula(): void
    {
        $byCareer = $this->report('matricula', "?career_id={$this->careersByCode['PSI']->id}");
        $this->assertSame(['B-PSI-02-262-001'], array_column($this->table($byCareer, 'Matriculados'), 'code'));
        $this->assertSame('Psicología', $byCareer['filters']['Carrera']);

        $byGroup = $this->report('matricula', '?group=A');
        $this->assertCount(2, $this->table($byGroup, 'Matriculados'));

        $byClassroom = $this->report('matricula', "?classroom_id={$this->classroomsByNumber['02']->id}");
        $this->assertSame(['Rosa Palomino'], array_column($this->table($byClassroom, 'Matriculados'), 'student'));
    }

    // ---------- Pagos ----------

    public function test_pagos_situacion_deudores_e_ingresos(): void
    {
        $report = $this->report('pagos');
        $summary = $report['summary'];

        $this->assertSame('S/ 400.00', $summary['Ingresos (pagos validados)']);
        $this->assertSame(1, $summary['Matrículas al día (pagado)']);     // Ana
        $this->assertSame(1, $summary['Matrículas con pago parcial']);    // Luis
        $this->assertSame(2, $summary['Matrículas sin pago']);            // Rosa, Jhon (Mia rechazada no figura)
        $this->assertSame(3, $summary['Deudores']);
        $this->assertSame('S/ 800.00', $summary['Deuda total']);
        $this->assertSame(1, $summary['Pagos validados parciales (cuotas)']);
        $this->assertSame(1, $summary['Pagos validados completos']);

        $situation = collect($this->table($report, 'Situación de pago por matrícula'))->keyBy('dni');
        $this->assertCount(4, $situation);
        $this->assertEquals([100, 50, 200, 'Parcial'], [$situation['71000002']['validated'], $situation['71000002']['pending'], $situation['71000002']['balance'], $situation['71000002']['status']]);
        $this->assertSame('Pendiente', $situation['71000004']['enrollment_status']);

        $this->assertSame(['71000002', '71000003', '71000004'], array_column($this->table($report, 'Deudores'), 'dni'));
    }

    // ---------- Académico ----------

    public function test_academico_resultados_y_promedios(): void
    {
        $report = $this->report('academico');

        $this->assertSame(2, $report['summary']['Exámenes publicados']);   // el borrador no aparece
        $this->assertSame(6, $report['summary']['Resultados incluidos']);

        $s2 = $this->table($report, 'Resultados: Simulacro 2 (01/10/2026)');
        $this->assertSame([[1, 1, 'Ana Quispe'], [1, 1, 'Rosa Palomino'], [2, 2, 'Luis Ccoica']], array_map(fn ($r) => [$r['rank'], $r['rank_in_group'], $r['student']], $s2));
        $this->assertSame('01', $s2[0]['classroom']);

        $byClassroom = collect($this->table($report, 'Promedios por aula'))->where('exam', 'Simulacro 2')->keyBy('classroom');
        $this->assertEquals([2, 25, 30, 20], array_values(array_intersect_key($byClassroom['01'], array_flip(['participants', 'average', 'max', 'min']))));
        $this->assertEquals(30, $byClassroom['02']['average']);

        $byCareer = collect($this->table($report, 'Promedios por carrera'))->where('exam', 'Simulacro 1')->keyBy('career');
        $this->assertEquals(12.5, $byCareer['Ingeniería de Sistemas']['average']);   // (10 + 15) / 2
        $this->assertEquals(5, $byCareer['Psicología']['average']);
    }

    public function test_academico_por_examen_y_por_grupo(): void
    {
        $report = $this->report('academico', "?exam_id={$this->exams['s1']->id}&group=B");

        $this->assertSame(1, $report['summary']['Exámenes publicados']);
        $this->assertSame(['Rosa Palomino'], array_column($this->table($report, 'Resultados: Simulacro 1 (01/09/2026)'), 'student'));
    }

    // ---------- Asistencia ----------

    public function test_asistencia_por_estudiante_aula_y_riesgo(): void
    {
        $report = $this->report('asistencia');

        $this->assertSame(10, $report['summary']['Registros de asistencia']);
        $this->assertEquals(40.0, $report['summary']['Asistencia general (%)']);
        $this->assertSame(1, $report['summary']['En riesgo (desde 2 faltas)']);
        $this->assertSame(1, $report['summary']['Inhabilitados (desde 3 faltas)']);

        $students = collect($this->table($report, 'Asistencia por estudiante'))->keyBy('dni');
        $this->assertEquals([4, 2, 1, 1, 75.0, 'Activo'], array_values(array_intersect_key($students['71000001'], array_flip(['total', 'present', 'late', 'absent', 'rate', 'risk']))));
        $this->assertEquals([33.3, 'En riesgo'], [$students['71000002']['rate'], $students['71000002']['risk']]);
        $this->assertEquals([0.0, 'Inhabilitado'], [$students['71000003']['rate'], $students['71000003']['risk']]);

        $this->assertSame(['Rosa Palomino', 'Luis Ccoica'], array_column($this->table($report, 'Estudiantes en riesgo e inhabilitados'), 'student'));

        $byClassroom = collect($this->table($report, 'Asistencia por aula'))->keyBy('classroom');
        $this->assertEquals([7, 4, 3, 57.1], array_values(array_intersect_key($byClassroom['01'], array_flip(['total', 'attended', 'absent', 'rate']))));
        $this->assertEquals(0.0, $byClassroom['02']['rate']);
    }

    // ---------- Formatos ----------

    public function test_exporta_a_pdf(): void
    {
        foreach (['matricula', 'pagos', 'academico', 'asistencia'] as $type) {
            $response = $this->actingAs($this->admin)->get("/api/admin/reports/{$type}?format=pdf")->assertOk();

            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
            $this->assertStringStartsWith('%PDF', $response->getContent());
            $this->assertStringContainsString('2026-ii', $response->headers->get('Content-Disposition'));
        }
    }

    public function test_exporta_a_excel_con_encabezado_y_una_hoja_por_tabla(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/admin/reports/matricula?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('Content-Type'));

        $sheets = $this->readXlsx($response);

        $this->assertSame(['Resumen', '1. Matriculados', '2. Matriculados por carrera', '3. Matriculados por aula'], array_keys($sheets));
        $this->assertSame('CEPRE UNAJMA', $sheets['Resumen'][0][0]);
        $this->assertContains(['Semestre', '2026-II'], $sheets['Resumen']);
        $this->assertSame(['Código', 'Estudiante', 'DNI', 'Carrera', 'Grupo', 'Aula', 'Aprobada'], $sheets['1. Matriculados'][0]);
        $this->assertSame('A-SIS-01-262-001', $sheets['1. Matriculados'][1][0]);
    }

    public function test_la_fecha_del_reporte_esta_en_hora_de_lima(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-17 02:30:00', 'UTC'));

        $report = $this->report('matricula');

        // 02:30 UTC = 21:30 del día anterior en Lima (UTC−5).
        $this->assertStringStartsWith('2026-11-16T21:30:00-05:00', $report['generated_at']);
    }

    public function test_el_pdf_escapa_los_datos(): void
    {
        $document = new ReportDocument('Prueba', ['Semestre' => '2026-II'], [], [
            new ReportTable('Tabla', ['name' => 'Nombre'], [['name' => '<script>alert(1)</script>']]),
        ]);

        $html = view('reports.document', ['document' => $document])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    // ---------- Validación y permisos ----------

    public function test_tipo_formato_y_filtros_invalidos(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/reports/notas')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->actingAs($this->admin)->getJson('/api/admin/reports/pagos?format=docx')->assertUnprocessable()->assertJsonValidationErrors('format');
        $this->actingAs($this->admin)->getJson('/api/admin/reports/pagos?group=D')->assertUnprocessable()->assertJsonValidationErrors('group');
    }

    public function test_solo_administracion_y_coordinacion(): void
    {
        $this->actingAs($this->coordinator)->getJson('/api/admin/reports/pagos')->assertOk();
        $this->actingAs($this->students['ana'])->getJson('/api/admin/reports/pagos')->assertForbidden();
        $this->actingAs(User::factory()->teacher()->create())->get('/api/admin/reports/matricula?format=pdf')->assertForbidden();
    }

    /** @return array<string, list<list<mixed>>> hoja => filas */
    private function readXlsx(TestResponse $response): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, file_get_contents($response->baseResponse->getFile()->getPathname()));

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);

        return $sheets;
    }
}
