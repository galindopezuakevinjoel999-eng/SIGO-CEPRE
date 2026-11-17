<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamResult;
use Illuminate\Http\Request;

/** Resultados del propio estudiante (RF33, RF34). */
class ExamResultController extends Controller
{
    /**
     * Historial de notas por semestre: solo exámenes publicados.
     * ?semester_id= filtra un semestre.
     */
    public function mine(Request $request)
    {
        $request->validate(['semester_id' => ['sometimes', 'integer', 'exists:semesters,id']]);

        $results = ExamResult::query()
            ->where('student_id', $request->user()->id)
            ->whereHas('exam', fn ($q) => $q->published()
                ->when($request->filled('semester_id'), fn ($e) => $e->where('semester_id', $request->integer('semester_id'))))
            ->with('exam.semester:id,code,name,year,cycle')
            ->get();

        // Participantes por examen y grupo, para mostrar "puesto 3 de 120".
        $examIds = $results->pluck('exam_id')->unique();
        $participants = ExamResult::whereIn('exam_id', $examIds)->selectRaw('exam_id, count(*) as total')->groupBy('exam_id')->pluck('total', 'exam_id');
        $inGroup = ExamResult::whereIn('exam_id', $examIds)->whereNotNull('group')
            ->select('exam_id', 'group')->selectRaw('count(*) as total')->groupBy('exam_id', 'group')->get()
            ->mapWithKeys(fn ($row) => ["{$row->exam_id}-{$row->group}" => (int) $row->total]);

        $history = $results
            ->sortByDesc(fn (ExamResult $r) => [$r->exam->semester?->year, $r->exam->semester?->cycle, $r->exam->date?->timestamp])
            ->groupBy(fn (ExamResult $r) => $r->exam->semester_id ?? 0)
            ->map(fn ($semesterResults) => [
                'semester' => $semesterResults->first()->exam->semester?->only(['id', 'code', 'name']),
                'average_score' => round($semesterResults->avg('score'), 3),
                'results' => $semesterResults->sortByDesc(fn ($r) => $r->exam->date?->timestamp)->values()->map(fn (ExamResult $r) => [
                    'exam' => $r->exam->only(['id', 'name', 'type', 'date', 'group', 'total_questions']),
                    'correct' => $r->correct,
                    'wrong' => $r->wrong,
                    'blank' => $r->blank,
                    'score' => $r->score,
                    'rank' => $r->rank,
                    'participants' => (int) ($participants[$r->exam_id] ?? 0),
                    'group' => $r->group,
                    'rank_in_group' => $r->rank_in_group,
                    'group_participants' => $r->group ? ($inGroup["{$r->exam_id}-{$r->group}"] ?? 0) : null,
                ]),
            ])
            ->values();

        return response()->json($history);
    }
}
