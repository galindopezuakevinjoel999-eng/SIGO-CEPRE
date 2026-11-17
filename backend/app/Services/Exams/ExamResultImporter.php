<?php

namespace App\Services\Exams;

use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Importa los resultados de un examen (RF31, RF32).
 *
 * Por cada fila: valida DNI y respuestas, busca al estudiante por DNI, calcula
 * el puntaje con los puntos del examen y comprueba que tenga matrícula aprobada
 * en el semestre del examen (de ahí sale su grupo). Luego genera el ranking
 * general y por grupo, y deja constancia en audit_logs.
 *
 * Reimportar reemplaza todos los resultados del examen. Si ninguna fila es
 * válida no se toca nada: los resultados anteriores se conservan.
 *
 * El formato del archivo lo resuelve el ResultReader (hoy CsvTemplateReader);
 * esta clase solo trabaja con filas normalizadas.
 */
class ExamResultImporter
{
    public function __construct(
        private ExamRanking $ranking,
        private AuditLogger $audit,
    ) {}

    /** @throws InvalidResultFile */
    public function import(Exam $exam, ResultReader $reader, string $path, User $actor, ?string $fileName = null): ImportReport
    {
        $report = new ImportReport;
        $rows = collect($reader->rows($path));
        $report->totalRows = $rows->count();

        $students = User::where('role', User::ROLE_ESTUDIANTE)
            ->whereIn('dni', $rows->pluck('dni')->filter()->unique()->all())
            ->get()
            ->keyBy('dni');

        $groups = $this->groupsInSemester($exam, $students);

        $valid = [];
        $firstLineByDni = [];

        foreach ($rows as $row) {
            $errors = $this->validate($row, $exam);

            if (! $errors && isset($firstLineByDni[$row->dni])) {
                $errors[] = "DNI repetido en el archivo (ya aparece en la línea {$firstLineByDni[$row->dni]}).";
            }

            if ($errors) {
                $report->addError($row, $errors);

                continue;
            }

            $firstLineByDni[$row->dni] = $row->line;
            $student = $students->get($row->dni);

            if (! $student) {
                $report->addNotFound($row);

                continue;
            }

            $group = $groups[$student->id] ?? null;

            if ($exam->semester_id && ! $group) {
                $report->addError($row, ['El estudiante no tiene matrícula aprobada en el semestre del examen.']);

                continue;
            }

            if ($exam->group && $group !== $exam->group) {
                $report->addError($row, ["El estudiante es del grupo {$group}; el examen es solo para el grupo {$exam->group}."]);

                continue;
            }

            [$correct, $wrong, $blank] = [(int) $row->correct, (int) $row->wrong, (int) $row->blank];

            $valid[] = [
                'exam_id' => $exam->id,
                'student_id' => $student->id,
                'correct' => $correct,
                'wrong' => $wrong,
                'blank' => $blank,
                'score' => $exam->score($correct, $wrong, $blank),
                'group' => $group,
            ];
        }

        $report->imported = count($valid);

        if ($valid) {
            $this->replaceResults($exam, $valid, $report, $actor, $fileName);
        }

        return $report;
    }

    /** @return list<string> */
    private function validate(ResultRow $row, Exam $exam): array
    {
        $errors = [];

        if (! preg_match('/^\d{8}$/', $row->dni)) {
            $errors[] = 'DNI inválido: debe tener 8 dígitos.';
        }

        $labels = ['correct' => 'correctas', 'wrong' => 'incorrectas', 'blank' => 'en_blanco'];
        foreach ($labels as $field => $label) {
            if ($row->{$field} === null || ! ctype_digit($row->{$field})) {
                $errors[] = "\"{$label}\" debe ser un número entero mayor o igual a 0.";
            }
        }

        if (! $errors && $exam->total_questions) {
            $sum = (int) $row->correct + (int) $row->wrong + (int) $row->blank;

            if ($sum !== $exam->total_questions) {
                $errors[] = "La suma de respuestas ({$sum}) no coincide con el total de preguntas ({$exam->total_questions}).";
            }
        }

        return $errors;
    }

    /**
     * Grupo de cada estudiante: el de la carrera de su matrícula aprobada en
     * el semestre del examen. Sin semestre (exámenes antiguos), sin grupo.
     *
     * @return array<int, string> student_id => grupo
     */
    private function groupsInSemester(Exam $exam, Collection $students): array
    {
        if (! $exam->semester_id || $students->isEmpty()) {
            return [];
        }

        return Enrollment::with('career:id,group')
            ->where('semester_id', $exam->semester_id)
            ->where('status', 'approved')
            ->whereIn('student_id', $students->pluck('id'))
            ->get()
            ->mapWithKeys(fn (Enrollment $e) => [$e->student_id => $e->group ?? $e->career?->group])
            ->filter()
            ->all();
    }

    private function replaceResults(Exam $exam, array $valid, ImportReport $report, User $actor, ?string $fileName): void
    {
        DB::transaction(function () use ($exam, $valid, $report, $actor, $fileName) {
            $replaced = $exam->results()->count();
            $exam->results()->delete();

            $now = now();
            foreach (array_chunk($valid, 500) as $chunk) {
                ExamResult::insert(array_map(fn ($r) => [...$r, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }

            $this->ranking->recalculate($exam);
            $exam->forceFill(['results_imported_at' => $now])->save();

            $this->audit->record($actor, null, AuditLogger::EXAM_RESULTS_IMPORTED, null, [
                'exam_id' => $exam->id,
                'exam_name' => $exam->name,
                'file' => $fileName,
                'total_rows' => $report->totalRows,
                'imported' => $report->imported,
                'not_found' => count($report->notFound),
                'errors' => count($report->errors),
                'replaced_results' => $replaced,
            ]);
        });
    }
}
