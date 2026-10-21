{{--
    Acta PDF de una entrega (RN-041) sobre el layout común pdf.layout (logo y
    marca de agua). Todo dato se imprime escapado con {{ }}: nunca HTML del
    usuario (las observaciones las escribe el usuario).

    RN-022 / Ley 1581: del empleado que recibe solo nombre completo, cargo y
    firma; nunca documento, teléfono, correo ni tallas.

    Variables: $title, $orientation, $brandLogo, $delivery (App\Models\Delivery
    con DeliveryResource::RELATIONS y deliveredBy.employee.position),
    $signatureDataUri (firma verificada, M-3), $generatedAt (Y-m-d H:i en
    America/Bogota) y $generatedBy (nombre del usuario que descarga).
--}}
@extends('pdf.layout')

@php
    $local = fn (?\Carbon\CarbonInterface $date) => $date?->copy()->setTimezone(\App\Models\Delivery::LOCAL_TIMEZONE)->format('Y-m-d H:i');
    $returns = $delivery->items->flatMap(fn ($item) => $item->returns->map(fn ($return) => ['item' => $item, 'return' => $return]));
@endphp

@section('styles')
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th, td { border-bottom: 0.5pt solid #9a9a9a; padding: 2.5pt 4pt; text-align: left; vertical-align: top; }
        th { background-color: #e9e9e9; font-weight: bold; }
        td.number, th.number { text-align: right; }
        table.fields td { border: none; padding: 1.5pt 4pt 1.5pt 0; }
        table.fields td.label { font-weight: bold; width: 28%; }
        table.parties td { border: none; width: 50%; padding: 0 8pt 0 0; }
        img.signature { width: 60mm; border-bottom: 0.5pt solid #111111; }
        p.empty { color: #555555; margin: 2pt 0; }
        p.footer { margin-top: 14pt; color: #444444; font-size: 7.5pt; }
@endsection

@section('content')
    <h1>{{ $title }}</h1>

    <table class="fields">
        <tr><td class="label">Fecha de entrega</td><td>{{ $local($delivery->delivered_at) }}</td></tr>
        <tr><td class="label">Tipo</td><td>{{ $delivery->kind->label() }}</td></tr>
        @if ($delivery->due_on)
            <tr><td class="label">Fecha límite de devolución</td><td>{{ $delivery->due_on->toDateString() }}</td></tr>
        @endif
        <tr><td class="label">Ubicación de origen</td><td>{{ $delivery->fromLocation->name }}</td></tr>
        <tr><td class="label">Estado actual</td><td>{{ $delivery->status()->label() }}</td></tr>
        <tr><td class="label">Observaciones</td><td>{{ $delivery->notes ?? '—' }}</td></tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <h2>Entrega</h2>
                <table class="fields">
                    <tr><td class="label">Nombre</td><td>{{ $delivery->deliveredBy->name }}</td></tr>
                    <tr><td class="label">Cargo</td><td>{{ $delivery->deliveredBy->employee?->position?->name ?? '—' }}</td></tr>
                </table>
            </td>
            <td>
                <h2>Recibe</h2>
                <table class="fields">
                    <tr><td class="label">Nombre</td><td>{{ $delivery->employee->full_name }}</td></tr>
                    <tr><td class="label">Cargo</td><td>{{ $delivery->employee->position?->name ?? '—' }}</td></tr>
                </table>
                <img class="signature" src="{{ $signatureDataUri }}" alt="Firma de quien recibe">
            </td>
        </tr>
    </table>

    <h2>Artículos</h2>
    <table>
        <tr>
            <th>Artículo</th>
            <th>Placa</th>
            <th class="number">Cantidad</th>
            <th class="number">Devuelta</th>
            <th class="number">Pendiente</th>
        </tr>
        @foreach ($delivery->items as $item)
            <tr>
                <td>{{ $item->assetType->name }}</td>
                <td>{{ $item->asset?->plate ?? '—' }}</td>
                <td class="number">{{ $item->quantity }}</td>
                <td class="number">{{ $item->returned_quantity }}</td>
                <td class="number">{{ $item->pending_quantity }}</td>
            </tr>
        @endforeach
    </table>

    <h2>Devoluciones</h2>
    @if ($returns->isEmpty())
        <p class="empty">Sin devoluciones.</p>
    @else
        <table>
            <tr>
                <th>Fecha</th>
                <th>Artículo</th>
                <th class="number">Cantidad</th>
                <th>Estado</th>
                <th>Recibió</th>
                <th>Ubicación</th>
            </tr>
            @foreach ($returns as $row)
                <tr>
                    <td>{{ $local($row['return']->returned_at) }}</td>
                    <td>{{ $row['item']->asset?->plate ?? $row['item']->assetType->name }}</td>
                    <td class="number">{{ $row['return']->quantity }}</td>
                    <td>{{ $row['return']->condition->label() }}</td>
                    <td>{{ $row['return']->receivedBy->name }}</td>
                    <td>{{ $row['return']->toLocation?->name ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="footer">
        Generado el {{ $generatedAt }} por {{ $generatedBy }}.<br>
        {{-- M-2 (revisión de seguridad de RN-041): el acta es una copia. --}}
        Copia generada del registro {{ $delivery->code }} el {{ $generatedAt }}. La información vigente es la del sistema ActivosB31.<br>
        Huella SHA-256 de la firma: {{ $delivery->signature_sha256 }}
    </p>
@endsection
