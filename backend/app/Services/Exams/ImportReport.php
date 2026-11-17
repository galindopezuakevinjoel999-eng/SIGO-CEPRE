<?php

namespace App\Services\Exams;

use JsonSerializable;

/** Informe de una importación de resultados. */
final class ImportReport implements JsonSerializable
{
    public int $imported = 0;

    public int $totalRows = 0;

    /** @var list<array{line: int, dni: string}> */
    public array $notFound = [];

    /** @var list<array{line: int, dni: string, errors: list<string>}> */
    public array $errors = [];

    public function addNotFound(ResultRow $row): void
    {
        $this->notFound[] = ['line' => $row->line, 'dni' => $row->dni];
    }

    /** @param  list<string>  $messages */
    public function addError(ResultRow $row, array $messages): void
    {
        $this->errors[] = ['line' => $row->line, 'dni' => $row->dni, 'errors' => $messages];
    }

    public function jsonSerialize(): array
    {
        return [
            'total_rows' => $this->totalRows,
            'imported' => $this->imported,
            'not_found' => $this->notFound,
            'errors' => $this->errors,
        ];
    }
}
