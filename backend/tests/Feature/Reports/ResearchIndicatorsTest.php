<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\BuildsReportScenario;
use Tests\TestCase;

/** GET /api/admin/research-indicators — valores esperados en BuildsReportScenario. */
class ResearchIndicatorsTest extends TestCase
{
    use BuildsReportScenario;
    use RefreshDatabase;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildReportScenario();
        $this->data = $this->actingAs($this->admin)->getJson('/api/admin/research-indicators')->assertOk()->json();
    }

    // ---------- D1 Eficiencia temporal ----------

    public function test_d1_tiempo_de_atencion_administrativa(): void
    {
        // Envío → aprobación: Ana 18 h, Luis 11,5 h, Rosa 24 h. Jhon (pendiente) y
        // Mia (rechazada) no se aprobaron; la del semestre anterior no cuenta.
        $this->assertEquals(
            ['count' => 3, 'average' => 17.83, 'min' => 11.5, 'max' => 24, 'average_days' => 0.74],
            $this->data['d1_time_efficiency']['enrollment_attention_hours'],
        );
    }

    public function test_d1_tiempo_de_registro_del_estudiante(): void
    {
        // Creación → envío, en cualquier estado: 6 + 0,5 + 24 + 2 + 0,25 = 32,75 h / 5.
        $this->assertEquals(
            ['count' => 5, 'average' => 6.55, 'min' => 0.25, 'max' => 24, 'average_days' => 0.27],
            $this->data['d1_time_efficiency']['enrollment_registration_hours'],
        );
    }

    public function test_d1_la_atencion_es_el_primer_indicador_de_tiempo(): void
    {
        $this->assertSame(
            ['enrollment_attention_hours', 'enrollment_registration_hours', 'payment_validation_hours'],
            array_keys($this->data['d1_time_efficiency']),
        );
    }

    public function test_d1_tiempo_de_validacion_de_pago(): void
    {
        // Solo validados: Ana 12 h, Luis 24 h. Los rechazados y el pendiente no cuentan.
        $this->assertEquals(
            ['count' => 2, 'average' => 18, 'min' => 12, 'max' => 24, 'average_days' => 0.75],
            $this->data['d1_time_efficiency']['payment_validation_hours'],
        );
    }

    // ---------- D2 Exactitud operativa ----------

    public function test_d2_rechazos_de_pagos_y_motivos(): void
    {
        $payments = $this->data['d2_operational_accuracy']['payments'];

        $this->assertSame(4, $payments['decided']);      // 2 validados + 2 rechazados
        $this->assertSame(2, $payments['rejected']);
        $this->assertEquals(50.0, $payments['rejected_rate']);
        $this->assertSame(1, $payments['pending']);
        // "Comprobante ilegible" y "comprobante  ilegible" son el mismo motivo.
        $this->assertSame([['reason' => 'Comprobante ilegible', 'count' => 2]], $payments['top_reasons']);
    }

    public function test_d2_rechazos_de_matriculas(): void
    {
        $enrollments = $this->data['d2_operational_accuracy']['enrollments'];

        $this->assertSame([4, 1, 1], [$enrollments['decided'], $enrollments['rejected'], $enrollments['pending']]);
        $this->assertEquals(25.0, $enrollments['rejected_rate']);
        $this->assertSame([['reason' => 'Documentos incompletos', 'count' => 1]], $enrollments['top_reasons']);
    }

    public function test_d2_errores_en_importaciones(): void
    {
        $this->assertEquals(
            ['imports' => 2, 'total_rows' => 8, 'imported' => 6, 'not_found' => 1, 'errors' => 1, 'error_rate' => 25.0],
            $this->data['d2_operational_accuracy']['exam_imports'],
        );
    }

    // ---------- D4 Trazabilidad ----------

    public function test_d4_cobertura_de_auditoria_por_operacion(): void
    {
        $ops = $this->data['d4_traceability']['operations'];

        $this->assertEquals([4, 3, 75.0], [$ops['enrollment_decisions']['total'], $ops['enrollment_decisions']['audited'], $ops['enrollment_decisions']['rate']]); // falta Rosa
        $this->assertEquals([4, 3, 75.0], [$ops['payment_reviews']['total'], $ops['payment_reviews']['audited'], $ops['payment_reviews']['rate']]);              // falta el de Jhon
        $this->assertEquals([2, 2, 100.0], [$ops['exam_imports']['total'], $ops['exam_imports']['audited'], $ops['exam_imports']['rate']]);
        $this->assertEquals([2, 1, 50.0], [$ops['exam_publications']['total'], $ops['exam_publications']['audited'], $ops['exam_publications']['rate']]);   // falta el Simulacro 1

        $this->assertEquals(['total' => 12, 'audited' => 9, 'rate' => 75.0], $this->data['d4_traceability']['overall']);
    }

    public function test_d4_acciones_por_usuario_administrativo(): void
    {
        $users = collect($this->data['d4_traceability']['actions_by_user'])->keyBy('name');

        // Admin: 2 aprobaciones, 2 validaciones, 2 importaciones, 1 publicación (la acción del semestre anterior no cuenta).
        $this->assertSame(7, $users['Admin CEPRE']['actions']);
        $this->assertSame([
            'enrollment_approved' => 2, 'exam_published' => 1, 'exam_results_imported' => 2, 'payment_validated' => 2,
        ], $users['Admin CEPRE']['by_action']);
        $this->assertSame(['coordinador', 2], [$users['Coordinación']['role'], $users['Coordinación']['actions']]);
    }

    // ---------- Exportación y permisos ----------

    public function test_exporta_a_excel(): void
    {
        $response = $this->actingAs($this->admin)->get('/api/admin/research-indicators?format=xlsx')->assertOk();

        $reader = new Reader;
        $reader->open($response->baseResponse->getFile()->getPathname());
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame([
            'Resumen', '1. D1 Eficiencia temporal (h)', '2. D2 Rechazos', '3. D2 Motivos de rechazo',
            '4. D2 Importaciones de exámenes', '5. D4 Trazabilidad', '6. D4 Acciones por usuario',
        ], array_keys($sheets));
        $this->assertContains(['D4 · Operaciones con registro de auditoría (%)', 75], $sheets['Resumen']);
        $this->assertSame(['Atención administrativa: envío → aprobación', 3, 17.83, 11.5, 24, 0.74], $sheets['1. D1 Eficiencia temporal (h)'][1]);
        $this->assertSame(['Registro del estudiante: creación → envío', 5, 6.55, 0.25, 24, 0.27], $sheets['1. D1 Eficiencia temporal (h)'][2]);
        $this->assertContains(['D1 · Atención administrativa de matrícula (h promedio)', 17.83], $sheets['Resumen']);
    }

    public function test_solo_el_administrador(): void
    {
        $this->actingAs($this->coordinator)
            ->getJson('/api/admin/research-indicators')
            ->assertForbidden()
            ->assertJsonPath('message', 'Solo administración puede ver los indicadores de investigación.');

        $this->actingAs($this->students['ana'])->getJson('/api/admin/research-indicators')->assertForbidden();
    }

    public function test_otro_semestre_tiene_sus_propios_indicadores(): void
    {
        $previous = $this->actingAs($this->admin)
            ->getJson("/api/admin/research-indicators?semester_id={$this->previousSemester->id}")
            ->assertOk()
            ->json();

        // Matrícula anterior a submitted_at: no entra en los tiempos de matrícula.
        $this->assertSame(0, $previous['d1_time_efficiency']['enrollment_attention_hours']['count']);
        $this->assertNull($previous['d1_time_efficiency']['enrollment_attention_hours']['average']);
        $this->assertSame(0, $previous['d1_time_efficiency']['enrollment_registration_hours']['count']);
        $this->assertEquals(100.0, $previous['d4_traceability']['operations']['enrollment_decisions']['rate']);
    }
}
