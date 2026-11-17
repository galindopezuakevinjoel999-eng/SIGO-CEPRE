<?php

namespace App\Reports\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Str;

/**
 * Reporte listo para renderizar en JSON, PDF o Excel: encabezado institucional,
 * filtros aplicados, resumen (indicadores) y tablas.
 */
final class ReportDocument
{
    public readonly string $institution;

    public readonly string $institutionFullName;

    public readonly \DateTimeInterface $generatedAt;

    /**
     * @param  array<string, string>  $filters  etiqueta => valor (ver ReportFilters::describe)
     * @param  array<string, scalar|null>  $summary  etiqueta => valor
     * @param  list<ReportTable>  $tables
     */
    public function __construct(
        public readonly string $title,
        public readonly array $filters,
        public readonly array $summary,
        public readonly array $tables,
        public readonly array $notes = [],
    ) {
        $this->institution = (string) SystemSetting::getValue('institution_name', 'CEPRE UNAJMA');
        $this->institutionFullName = (string) SystemSetting::getValue('institution_full_name', '');
        $this->generatedAt = now()->setTimezone(config('app.display_timezone'));
    }

    /** Nombre de archivo sin extensión: reporte-matricula-2026-ii-20261118. */
    public function fileName(): string
    {
        return Str::slug($this->title.' '.($this->filters['Semestre'] ?? '').' '.$this->generatedAt->format('Ymd'));
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'institution' => $this->institution,
            'generated_at' => $this->generatedAt->format(DATE_ATOM),
            'filters' => $this->filters,
            'summary' => $this->summary,
            'tables' => array_map(fn (ReportTable $t) => $t->toArray(), $this->tables),
            'notes' => $this->notes,
        ];
    }
}
