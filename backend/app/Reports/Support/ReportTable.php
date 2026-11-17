<?php

namespace App\Reports\Support;

/** Una tabla del reporte: se vuelve una tabla en el PDF y una hoja en el Excel. */
final class ReportTable
{
    /**
     * @param  array<string, string>  $columns  clave => encabezado
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly ?string $emptyMessage = 'Sin datos para los filtros seleccionados.',
    ) {}

    public function toArray(): array
    {
        return ['title' => $this->title, 'columns' => $this->columns, 'rows' => $this->rows];
    }
}
