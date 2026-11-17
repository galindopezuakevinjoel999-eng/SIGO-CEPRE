<?php

namespace App\Services\Exams;

/**
 * Una fila de resultados tal como viene del archivo, sin validar.
 * Los valores son texto: ExamResultImporter decide si son válidos.
 */
final class ResultRow
{
    public function __construct(
        public readonly int $line,
        public readonly string $dni,
        public readonly ?string $correct,
        public readonly ?string $wrong,
        public readonly ?string $blank,
    ) {}
}
