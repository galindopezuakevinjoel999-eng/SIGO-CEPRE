<?php

namespace App\Services\Exams;

use App\Models\Career;
use App\Models\Exam;
use App\Models\ExamResult;
use Illuminate\Support\Collection;

/**
 * Ingresantes de un examen: en cada carrera, los mejores puestos
 * (rank_in_career) hasta cubrir sus vacantes del ciclo, solo si alcanzan el
 * puntaje mínimo del ciclo (score ≥ min_admission_score; sin mínimo, solo
 * cuentan las vacantes).
 *
 * Empates: rank_in_career usa ranking de competencia (1, 1, 3). Si hay empate
 * en la última vacante, ingresan todos los empatados y la carrera se marca
 * con tie_at_cutoff (puede superar sus vacantes).
 */
class AdmissionService
{
    /**
     * @return list<array{career: array, vacancies: int, admitted: list<ExamResult>, others: list<ExamResult>, filled: int, tie_at_cutoff: bool}>
     */
    public function forExam(Exam $exam): array
    {
        $semester = $exam->semester;
        $minScore = $semester?->min_admission_score;
        $vacancies = $semester ? $semester->careers()->get()->mapWithKeys(fn (Career $c) => [$c->id => (int) $c->pivot->vacancies]) : collect();

        $results = $exam->results()
            ->whereNotNull('career_id')
            ->with(['student:id,name,dni', 'career:id,code,name,group'])
            ->orderBy('rank_in_career')
            ->orderBy('id')
            ->get()
            ->groupBy('career_id');

        $careerIds = $vacancies->keys()->merge($results->keys())->unique();
        $careers = Career::whereIn('id', $careerIds)->orderBy('group')->orderBy('name')->get();

        return $careers->map(function (Career $career) use ($results, $vacancies, $minScore) {
            $seats = $vacancies->get($career->id, 0);
            /** @var Collection<int, ExamResult> $candidates */
            $candidates = $results->get($career->id, collect());

            [$admitted, $others] = $candidates->partition(
                fn (ExamResult $r) => $r->rank_in_career <= $seats && ($minScore === null || $r->score >= $minScore)
            );

            return [
                'career' => $career->only(['id', 'code', 'name', 'group']),
                'vacancies' => $seats,
                'admitted' => $admitted->values()->all(),
                'others' => $others->values()->all(),
                'filled' => $admitted->count(),
                'tie_at_cutoff' => $admitted->count() > $seats,
            ];
        })->values()->all();
    }
}
