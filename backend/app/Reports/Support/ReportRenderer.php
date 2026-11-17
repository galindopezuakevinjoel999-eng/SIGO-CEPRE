<?php

namespace App\Reports\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Convierte un ReportDocument en la respuesta del formato pedido:
 *  json (por defecto, para el frontend), pdf (dompdf) o xlsx (OpenSpout:
 *  no necesita la extensión GD).
 */
class ReportRenderer
{
    public const FORMATS = ['json', 'pdf', 'xlsx'];

    public function render(ReportDocument $document, string $format): Response
    {
        return match ($format) {
            'pdf' => $this->pdf($document),
            'xlsx' => $this->xlsx($document),
            default => new JsonResponse($document->toArray()),
        };
    }

    private function pdf(ReportDocument $document): Response
    {
        // Sin recursos remotos: los datos del reporte se escapan en la vista y
        // dompdf no descarga nada de internet.
        return Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false])
            ->loadView('reports.document', ['document' => $document])
            ->setPaper('a4', $this->widestTable($document) > 6 ? 'landscape' : 'portrait')
            ->download($document->fileName().'.pdf');
    }

    /** Una hoja "Resumen" con encabezado e indicadores, y una hoja por tabla. */
    private function xlsx(ReportDocument $document): Response
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'report-'.Str::uuid().'.xlsx'; // se borra tras enviarlo
        $bold = (new Style)->setFontBold();

        $writer = new Writer;
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Resumen');
        $writer->addRow(Row::fromValues([$document->institution], $bold));
        if ($document->institutionFullName) {
            $writer->addRow(Row::fromValues([$document->institutionFullName]));
        }
        $writer->addRow(Row::fromValues([$document->title], $bold));
        foreach ($document->filters as $label => $value) {
            $writer->addRow(Row::fromValues([$label, $value]));
        }
        $writer->addRow(Row::fromValues(['Generado', $document->generatedAt->format('d/m/Y H:i')]));
        $writer->addRow(Row::fromValues([]));
        foreach ($document->summary as $label => $value) {
            $writer->addRow(Row::fromValues([$label, $value]));
        }
        foreach ($document->notes as $note) {
            $writer->addRow(Row::fromValues([$note]));
        }

        foreach ($document->tables as $index => $table) {
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($this->sheetName($table->title, $index));
            $writer->addRow(Row::fromValues(array_values($table->columns), $bold));

            foreach ($table->rows as $row) {
                $writer->addRow(Row::fromValues(array_map(fn ($key) => $row[$key] ?? null, array_keys($table->columns))));
            }
        }

        $writer->close();

        return response()->download($path, $document->fileName().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Excel limita los nombres de hoja a 31 caracteres, únicos y sin []:*?/\ */
    private function sheetName(string $title, int $index): string
    {
        $clean = trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $title));

        return mb_substr(($index + 1).'. '.$clean, 0, 31);
    }

    private function widestTable(ReportDocument $document): int
    {
        return max([0, ...array_map(fn (ReportTable $t) => count($t->columns), $document->tables)]);
    }
}
