<?php

namespace App\Services\Exams;

use App\Models\Exam;
use App\Models\ExamResult;
use Illuminate\Support\Collection;

/**
 * Puestos de un examen: general (rank) y dentro de cada grupo (rank_in_group).
 *
 * Empates: comparten puesto y el siguiente salta ("ranking de competencia"):
 * puntajes 18, 18, 15 → puestos 1, 1, 3. Los puntajes se comparan con 3
 * decimales, la misma precisión con que se guardan.
 */
class ExamRanking
{
    public function recalculate(Exam $exam): void
    {
        $results = $exam->results()->orderByDesc('score')->orderBy('id')->get();

        $this->assign($results, 'rank');

        $results->whereNotNull('group')
            ->groupBy('group')
            ->each(fn (Collection $group) => $this->assign($group->values(), 'rank_in_group'));

        // Puesto dentro de la carrera: base del cálculo de ingresantes.
        $results->whereNotNull('career_id')
            ->groupBy('career_id')
            ->each(fn (Collection $career) => $this->assign($career->values(), 'rank_in_career'));

        // foreach y no each(): each() se detiene si la función devuelve false
        // (p. ej. isDirty() de un resultado cuyo puesto no cambió).
        foreach ($results as $result) {
            if ($result->group === null) {
                $result->rank_in_group = null;
            }

            if ($result->career_id === null) {
                $result->rank_in_career = null;
            }

            if ($result->isDirty()) {
                $result->save();
            }
        }
    }

    /** Vuelve a calcular los puntajes con los puntos actuales del examen y el ranking. */
    public function rescore(Exam $exam): void
    {
        foreach ($exam->results()->whereNotNull('correct')->get() as $result) {
            $result->score = $exam->score($result->correct, $result->wrong, $result->blank);
            $result->save();
        }

        $this->recalculate($exam);
    }

    /** @param  Collection<int, ExamResult>  $ordered  de mayor a menor puntaje */
    private function assign(Collection $ordered, string $attribute): void
    {
        $previous = null;
        $rank = 0;

        foreach ($ordered as $position => $result) {
            $score = number_format($result->score, 3, '.', '');

            if ($score !== $previous) {
                $rank = $position + 1;
                $previous = $score;
            }

            $result->{$attribute} = $rank;
        }
    }
}
