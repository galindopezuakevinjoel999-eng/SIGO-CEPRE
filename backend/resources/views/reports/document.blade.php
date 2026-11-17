{{-- Reporte en PDF (dompdf). Todo dato se imprime escapado con {{ }}. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $document->title }}</title>
    <style>
        @page { margin: 28px 32px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1f2937; }
        .header { border-bottom: 2px solid #1e3a5f; padding-bottom: 6px; margin-bottom: 10px; }
        .header .institution { font-size: 14px; font-weight: bold; color: #1e3a5f; }
        .header .full-name { font-size: 9px; color: #4b5563; }
        .header .title { font-size: 12px; font-weight: bold; margin-top: 6px; }
        .meta td { padding: 1px 10px 1px 0; font-size: 9px; }
        .meta td.label { color: #4b5563; }
        h2 { font-size: 10.5px; color: #1e3a5f; margin: 14px 0 4px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #1e3a5f; color: #fff; text-align: left; padding: 4px 5px; font-size: 8.5px; }
        table.data td { padding: 3px 5px; border-bottom: 1px solid #e5e7eb; font-size: 8.5px; }
        table.data tr:nth-child(even) td { background: #f3f4f6; }
        table.summary td { padding: 2px 12px 2px 0; }
        table.summary td.value { font-weight: bold; }
        .empty { color: #6b7280; font-style: italic; }
        .notes { margin-top: 12px; color: #4b5563; font-size: 8px; }
        .footer { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 7.5px; color: #6b7280; text-align: right; }
    </style>
</head>
<body>
    <div class="footer">{{ $document->institution }} · Generado el {{ $document->generatedAt->format('d/m/Y H:i') }}</div>

    <div class="header">
        <div class="institution">{{ $document->institution }}</div>
        @if ($document->institutionFullName)
            <div class="full-name">{{ $document->institutionFullName }}</div>
        @endif
        <div class="title">{{ $document->title }}</div>
        <table class="meta">
            @foreach ($document->filters as $label => $value)
                <tr><td class="label">{{ $label }}:</td><td>{{ $value }}</td></tr>
            @endforeach
            <tr><td class="label">Fecha:</td><td>{{ $document->generatedAt->format('d/m/Y H:i') }}</td></tr>
        </table>
    </div>

    @if ($document->summary)
        <h2>Resumen</h2>
        <table class="summary">
            @foreach ($document->summary as $label => $value)
                <tr><td>{{ $label }}</td><td class="value">{{ $value ?? '—' }}</td></tr>
            @endforeach
        </table>
    @endif

    @foreach ($document->tables as $table)
        <h2>{{ $table->title }}</h2>
        @if (count($table->rows) === 0)
            <p class="empty">{{ $table->emptyMessage }}</p>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @foreach ($table->columns as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($table->rows as $row)
                        <tr>
                            @foreach (array_keys($table->columns) as $key)
                                <td>{{ $row[$key] ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    @if ($document->notes)
        <div class="notes">
            @foreach ($document->notes as $note)
                <div>{{ $note }}</div>
            @endforeach
        </div>
    @endif
</body>
</html>
