<?php

namespace App\Services\Exams;

/**
 * Convierte un archivo de resultados en filas normalizadas (DNI y cantidad de
 * respuestas correctas, incorrectas y en blanco).
 *
 * Hoy existe CsvTemplateReader (plantilla dni,correctas,incorrectas,en_blanco).
 * Para el formato real del lector óptico basta con otra implementación de esta
 * interfaz: ExamResultImporter no cambia.
 */
interface ResultReader
{
    /**
     * @return iterable<ResultRow>
     *
     * @throws InvalidResultFile si el archivo no tiene la estructura esperada
     */
    public function rows(string $path): iterable;
}
