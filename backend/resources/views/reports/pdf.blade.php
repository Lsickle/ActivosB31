{{--
    PDF de un reporte (008, RN-032, D-005) sobre el layout común pdf.layout
    (logo y marca de agua, RN-041). Solo tablas y CSS en el documento
    (dompdf: sin flexbox, grid ni Tailwind); las únicas imágenes son las de
    marca del layout. Todo dato se imprime escapado con {{ }}: nunca HTML del
    usuario.

    Variables: $title, $header (etiqueta => valor: fecha, usuario y filtros),
    $sections (list<App\Reports\ReportSection>), $orientation, $brandLogo.

    SC-001 (1 000 filas en menos de 10 s): las tablas largas se parten en
    bloques de 50 filas, cada uno con sus encabezados, y con bordes separados
    (dompdf es mucho más lento repartiendo una sola tabla grande con bordes
    colapsados entre páginas).
--}}
@extends('pdf.layout')

@php($rowsPerBlock = 50)

@section('styles')
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th, td { border-bottom: 0.5pt solid #9a9a9a; padding: 2.5pt 4pt; text-align: left; vertical-align: top; }
        th { background-color: #e9e9e9; font-weight: bold; }
        td.number { text-align: right; }
        table.meta td { border: none; padding: 1pt 4pt 1pt 0; }
        table.meta td.label, table.fields td.label { font-weight: bold; width: 32%; }
        p.empty { color: #555555; margin: 2pt 0; }
@endsection

@section('content')
    <h1>{{ $title }}</h1>

    <table class="meta">
        @foreach ($header as $label => $value)
            <tr>
                <td class="label">{{ $label }}</td>
                <td>{{ $value }}</td>
            </tr>
        @endforeach
    </table>

    @foreach ($sections as $section)
        <h2>{{ $section->title }}</h2>

        @if ($section->isFields())
            <table class="fields">
                @foreach ($section->rows as $row)
                    <tr>
                        <td class="label">{{ $row[0] }}</td>
                        <td>{{ $row[1] ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        @elseif ($section->rows === [])
            <p class="empty">Sin registros.</p>
        @else
            @foreach (array_chunk($section->rows, $rowsPerBlock) as $block)
                <table>
                    <tr>
                        @foreach ($section->columns as $column)
                            <th>{{ $column }}</th>
                        @endforeach
                    </tr>
                    @foreach ($block as $row)
                        <tr>
                            @foreach ($row as $value)
                                <td @class(['number' => is_int($value)])>{{ $value ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            @endforeach
        @endif
    @endforeach
@endsection
