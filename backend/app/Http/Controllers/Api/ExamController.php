<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Exam\ExamRequest;
use App\Http\Requests\Exam\ImportExamResultsRequest;
use App\Models\Exam;
use App\Services\AuditLogger;
use App\Services\Exams\AdmissionService;
use App\Services\Exams\CsvTemplateReader;
use App\Services\Exams\ExamRanking;
use App\Services\Exams\ExamResultImporter;
use App\Services\Exams\InvalidResultFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Exámenes (RF30–RF33). Permisos en App\Policies\ExamPolicy.
 * Flujo: crear (borrador) → importar CSV → revisar ranking → publicar.
 */
class ExamController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Exam::class);

        return response()->json(
            Exam::with('semester:id,code,name')
                ->withCount('results')
                ->when($request->filled('semester_id'), fn ($q) => $q->where('semester_id', $request->integer('semester_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->orderByDesc('date')
                ->paginate(20)
        );
    }

    public function store(ExamRequest $request)
    {
        $exam = Exam::create([...$request->examData(), 'status' => Exam::STATUS_DRAFT]);

        return response()->json($exam->load('semester:id,code,name'), 201);
    }

    public function show(Exam $exam)
    {
        Gate::authorize('view', $exam);

        return response()->json($exam->load('semester:id,code,name')->loadCount('results'));
    }

    /** Si cambian los puntos de un examen con resultados, se recalculan puntajes y ranking. */
    public function update(ExamRequest $request, Exam $exam, ExamRanking $ranking)
    {
        DB::transaction(function () use ($request, $exam, $ranking) {
            $exam->fill($request->examData());
            $pointsChanged = $exam->isDirty(['points_correct', 'points_wrong', 'points_blank']);
            $exam->save();

            if ($pointsChanged && $exam->results()->exists()) {
                $ranking->rescore($exam);
            }
        });

        return response()->json($exam->load('semester:id,code,name'));
    }

    public function destroy(Exam $exam)
    {
        Gate::authorize('delete', $exam);

        $exam->delete();

        return response()->json(['success' => true]);
    }

    /** Plantilla CSV vacía: dni,correctas,incorrectas,en_blanco */
    public function template()
    {
        Gate::authorize('create', Exam::class);

        return response(CsvTemplateReader::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-resultados.csv"',
        ]);
    }

    /**
     * Importa el CSV. Responde el informe: importados, DNI no encontrados y
     * filas con errores. 422 si el archivo no tiene la estructura esperada o
     * si ninguna fila se pudo importar (los resultados anteriores se conservan).
     */
    public function import(ImportExamResultsRequest $request, Exam $exam, ExamResultImporter $importer, CsvTemplateReader $reader)
    {
        $file = $request->file('file');

        try {
            $report = $importer->import($exam, $reader, $file->getRealPath(), $request->user(), $file->getClientOriginalName());
        } catch (InvalidResultFile $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($report->imported === 0) {
            return response()->json([
                'message' => 'No se importó ningún resultado. Revisa el informe; los resultados anteriores se conservan.',
                'report' => $report,
            ], 422);
        }

        return response()->json([
            'message' => "Se importaron {$report->imported} resultados.",
            'report' => $report,
        ]);
    }

    /** Ranking del examen (admin y coordinador), con filtro opcional ?group=A. */
    public function results(Request $request, Exam $exam)
    {
        Gate::authorize('view', $exam);

        return response()->json(
            $exam->results()
                ->with('student:id,name,dni')
                ->when($request->filled('group'), fn ($q) => $q->where('group', strtoupper($request->input('group'))))
                ->orderBy('rank')
                ->orderBy('id')
                ->paginate(100)
        );
    }

    /** Ingresantes por carrera según vacantes y puntaje mínimo del ciclo (admin y coordinador). */
    public function admitted(Exam $exam, AdmissionService $admission)
    {
        Gate::authorize('view', $exam);

        $careers = collect($admission->forExam($exam))->map(fn ($c) => [
            ...$c,
            'admitted' => array_map(fn ($r) => $this->admittedRow($r), $c['admitted']),
            'others' => array_map(fn ($r) => $this->admittedRow($r), $c['others']),
        ]);

        return response()->json([
            'exam' => $exam->only(['id', 'name', 'date', 'status']),
            'min_admission_score' => $exam->semester?->min_admission_score,
            'careers' => $careers,
        ]);
    }

    private function admittedRow($result): array
    {
        return [
            'student' => $result->student?->only(['id', 'name', 'dni']),
            'score' => $result->score,
            'rank' => $result->rank,
            'rank_in_career' => $result->rank_in_career,
        ];
    }

    /** Publica los resultados: desde ahora los estudiantes los ven (RF33). */
    public function publish(Request $request, Exam $exam, AuditLogger $audit)
    {
        Gate::authorize('publish', $exam);

        $count = $exam->results()->count();

        if ($count === 0) {
            return response()->json(['message' => 'Importa los resultados antes de publicar el examen.'], 422);
        }

        DB::transaction(function () use ($request, $exam, $audit, $count) {
            $exam->forceFill([
                'status' => Exam::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $request->user()->id,
            ])->save();

            $audit->record($request->user(), null, AuditLogger::EXAM_PUBLISHED, null, [
                'exam_id' => $exam->id,
                'exam_name' => $exam->name,
                'results' => $count,
            ]);
        });

        return response()->json($exam);
    }
}
