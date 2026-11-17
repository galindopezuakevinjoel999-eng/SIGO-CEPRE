<?php

namespace App\Services\Exams;

/**
 * Lee la plantilla CSV del CEPRE: dni,correctas,incorrectas,en_blanco
 *
 * - Acepta coma o punto y coma (Excel en español guarda con ";").
 * - Ignora el BOM de UTF-8, mayúsculas y espacios en el encabezado, y las
 *   líneas vacías.
 * - El orden de las columnas puede variar; las columnas extra se ignoran.
 */
class CsvTemplateReader implements ResultReader
{
    /** Columnas de la plantilla, en el orden en que se descarga. */
    public const COLUMNS = ['dni', 'correctas', 'incorrectas', 'en_blanco'];

    public function rows(string $path): iterable
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new InvalidResultFile('No se pudo leer el archivo.');
        }

        try {
            $first = fgets($handle);

            if ($first === false || trim($this->stripBom($first)) === '') {
                throw new InvalidResultFile('El archivo está vacío.');
            }

            $header = $this->stripBom($first);
            $delimiter = substr_count($header, ';') > substr_count($header, ',') ? ';' : ',';
            $columns = $this->columnPositions(str_getcsv(trim($header), $delimiter, '"', ''));

            $line = 1;
            while (($fields = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;

                if ($fields === [null] || implode('', array_map('trim', $fields)) === '') {
                    continue; // línea vacía
                }

                $value = fn (string $column) => isset($fields[$columns[$column]]) ? trim($fields[$columns[$column]]) : null;

                yield new ResultRow(
                    line: $line,
                    dni: (string) $value('dni'),
                    correct: $value('correctas'),
                    wrong: $value('incorrectas'),
                    blank: $value('en_blanco'),
                );
            }
        } finally {
            fclose($handle);
        }
    }

    /** CSV de la plantilla vacía, para descargar. */
    public static function template(): string
    {
        return implode(',', self::COLUMNS)."\n";
    }

    /** @return array<string, int> columna => posición */
    private function columnPositions(array $header): array
    {
        $normalized = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $positions = [];
        $missing = [];

        foreach (self::COLUMNS as $column) {
            $index = array_search($column, $normalized, true);
            $index === false ? $missing[] = $column : $positions[$column] = $index;
        }

        if ($missing) {
            throw new InvalidResultFile(
                'Faltan columnas en el encabezado: '.implode(', ', $missing).'. Usa la plantilla: '.implode(',', self::COLUMNS).'.'
            );
        }

        return $positions;
    }

    private function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}
