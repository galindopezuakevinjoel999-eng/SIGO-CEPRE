<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\AcademicReport;
use App\Reports\AttendanceReport;
use App\Reports\DashboardMetrics;
use App\Reports\EnrollmentReport;
use App\Reports\PaymentReport;
use App\Reports\ResearchIndicators;
use App\Reports\Support\ReportFilters;
use App\Reports\Support\ReportRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Dashboard, reportes (RF38–RF41) e indicadores de la investigación.
 * Filtros comunes: semester_id (por defecto el activo), career_id, group, classroom_id.
 */
class ReportController extends Controller
{
    public const TYPES = ['matricula', 'pagos', 'academico', 'asistencia'];

    public function __construct(private ReportRenderer $renderer) {}

    /** GET /api/admin/dashboard */
    public function dashboard(Request $request, DashboardMetrics $metrics)
    {
        Gate::authorize('view-reports');

        return response()->json($metrics->build(ReportFilters::fromRequest($request)));
    }

    /** GET /api/admin/reports/{type}?format=json|pdf|xlsx */
    public function report(Request $request, string $type)
    {
        Gate::authorize('view-reports');

        $request->merge(['type' => $type]);
        $data = $request->validate([
            'type' => [Rule::in(self::TYPES)],
            'format' => ['sometimes', Rule::in(ReportRenderer::FORMATS)],
            'exam_id' => ['sometimes', 'integer', 'exists:exams,id'],
        ], ['type.in' => 'Tipo de reporte desconocido. Usa: '.implode(', ', self::TYPES).'.']);

        $filters = ReportFilters::fromRequest($request);

        $document = match ($type) {
            'matricula' => app(EnrollmentReport::class)->build($filters),
            'pagos' => app(PaymentReport::class)->build($filters),
            'academico' => app(AcademicReport::class)->build($filters, $data['exam_id'] ?? null),
            'asistencia' => app(AttendanceReport::class)->build($filters),
        };

        return $this->renderer->render($document, $data['format'] ?? 'json');
    }

    /** GET /api/admin/research-indicators?format=json|xlsx (solo admin) */
    public function researchIndicators(Request $request, ResearchIndicators $indicators)
    {
        Gate::authorize('view-research-indicators');

        $format = $request->validate(['format' => ['sometimes', Rule::in(['json', 'xlsx'])]])['format'] ?? 'json';
        $filters = ReportFilters::fromRequest($request);
        $data = $indicators->build($filters);

        return $format === 'xlsx'
            ? $this->renderer->render($indicators->toDocument($filters, $data), 'xlsx')
            : response()->json($data);
    }
}
