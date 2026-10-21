<?php

use App\Actions\Entregas\CreateDelivery;
use App\Actions\Inventario\RecordStockMovement;
use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';
require_once __DIR__.'/../../Support/reports.php';

/**
 * 008 — T003: exportación a Excel y PDF (RN-032, D-002, D-005). El archivo
 * sale de la misma clase que el JSON (D-001): mismo encabezado (ReportMeta),
 * mismas filas y totales. RN-022 también en los archivos. Celdas con
 * fórmulas escapadas (E-8 de la revisión de seguridad de 004).
 */
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

beforeEach(function () {
    Storage::fake('local');
    // 2026-10-20 03:00 UTC = 2026-10-19 22:00 en Bogotá.
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @return array{bodega: Location, cascos: AssetType, guantes: AssetType}
 */
function exportInventoryScenario(): array
{
    $bodega = Location::factory()->create(['name' => 'Bodega']);
    $inactiva = Location::factory()->create(['name' => 'Depósito viejo']);
    $category = AssetCategory::factory()->create(['name' => 'Protección']);
    $cascos = AssetType::factory()->for($category, 'category')->create(['name' => 'Casco', 'tracking_mode' => TrackingMode::Serialized]);
    $guantes = AssetType::factory()->for($category, 'category')->create(['name' => 'Guantes', 'tracking_mode' => TrackingMode::Quantity, 'min_stock' => 10]);
    $user = User::factory()->create();

    Asset::factory()->count(2)->for($cascos, 'assetType')->at($bodega)->create();
    Asset::factory()->for($cascos, 'assetType')->assignedTo()->create();
    app(RecordStockMovement::class)->handle(MovementType::Entry, $guantes, 6, null, $bodega, null, $user);
    $tmp = Location::factory()->create();
    app(RecordStockMovement::class)->handle(MovementType::Entry, $guantes, 2, null, $tmp, null, $user);
    app(RecordStockMovement::class)->handle(MovementType::Transfer, $guantes, 2, $tmp, $inactiva, null, $user);
    $inactiva->forceFill(['is_active' => false])->save();

    return compact('bodega', 'cascos', 'guantes');
}

/**
 * @param  array<string, mixed>  $data  ReportMeta del JSON
 * @param  list<list<mixed>>  $rows
 */
function expectSameMeta(array $data, array $rows, string $title): void
{
    $expected = [
        'Generado' => bogotaDateTime($data['generated_at']),
        'Generado por' => $data['generated_by']['name'],
    ];
    foreach ($data['filters'] as $filter) {
        $expected[$filter['label']] = $filter['value'];
    }

    expect($rows[0])->toBe([$title])
        ->and(xlsxHeader($rows))->toBe($expected);
}

describe('descargas: cabeceras y errores', function () {
    it('D-002: xlsx con Content-Disposition attachment y el nombre del contrato', function () {
        actingWithPermissions([Permission::ReportesVer->value]);

        $response = getReport('/api/reports/inventory?format=xlsx')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe(XLSX_MIME)
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="inventario-2026-10-19.xlsx"')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('D-002: pdf con Content-Disposition attachment y nombres por reporte', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $employee = Employee::factory()->create();
        $asset = Asset::factory()->create();
        $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);

        $files = [
            '/api/reports/inventory?format=pdf' => 'inventario-2026-10-19.pdf',
            "/api/reports/employees/{$employee->id}?format=pdf" => 'reporte-empleado-2026-10-19.pdf',
            "/api/reports/assets/{$asset->id}?format=pdf" => 'reporte-activo-2026-10-19.pdf',
            "/api/reports/asset-types/{$type->id}?format=xlsx" => 'reporte-tipo-2026-10-19.xlsx',
        ];

        foreach ($files as $url => $name) {
            $response = getReport($url)->assertOk();
            expect($response->headers->get('Content-Disposition'))->toBe("attachment; filename=\"{$name}\"");
        }

        $pdf = getReport('/api/reports/inventory?format=pdf');
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($pdf->getContent())->toStartWith('%PDF-');
    });

    it('los errores de una descarga son JSON (422 en `to`, 403 sin permiso)', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $employee = Employee::factory()->create();

        getReport("/api/reports/employees/{$employee->id}?from=2026-10-10&to=2026-10-01&format=xlsx")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);

        actingWithPermissions([Permission::EmpleadosVer->value]);
        getReport("/api/reports/employees/{$employee->id}?format=pdf")->assertForbidden()->assertJsonStructure(['message']);
    });

    it('limita las descargas a 10 por minuto por usuario (429 en JSON); el JSON del reporte no cuenta', function () {
        actingWithPermissions([Permission::ReportesVer->value]);

        for ($i = 0; $i < 10; $i++) {
            getReport('/api/reports/inventory?format=xlsx')->assertOk();
        }

        getReport('/api/reports/inventory?format=pdf')->assertTooManyRequests()->assertJsonStructure(['message']);
        $this->getJson('/api/reports/inventory')->assertOk();

        // Otro usuario no comparte el límite.
        actingWithPermissions([Permission::ReportesVer->value]);
        getReport('/api/reports/inventory?format=xlsx')->assertOk();
    });

    it('CORS expone Content-Disposition al frontend en otro origen', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $origin = config('cors.allowed_origins')[0];

        $response = $this->get('/api/reports/inventory?format=xlsx', ['Accept' => 'application/json', 'Origin' => $origin])->assertOk();

        expect($response->headers->get('Access-Control-Expose-Headers'))->toContain('Content-Disposition');
    });
});

describe('D-001: el Excel trae los mismos datos que el JSON', function () {
    it('inventario general', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = exportInventoryScenario();
        $url = "/api/reports/inventory?asset_category_id={$s['cascos']->asset_category_id}";

        $data = $this->getJson($url)->assertOk()->json('data');
        $rows = xlsxRows(getReport("{$url}&format=xlsx")->assertOk()->getContent());

        expectSameMeta($data, $rows, 'Inventario general');
        expect($data['filters'])->toBe([['label' => 'Categoría', 'value' => 'Protección']]);

        $serialized = xlsxTable($rows, 'Activos serializados');
        expect($serialized['header'])->toBe(['Categoría', 'Tipo', 'Ubicación', 'Estado', 'Cantidad'])
            ->and($serialized['rows'])->toBe(array_map(fn (array $row) => [
                $row['asset_type']['category']['name'], $row['asset_type']['name'], $row['location']['name'] ?? 'Sin ubicación', $row['status_label'], $row['count'],
            ], $data['serialized']))
            ->and($serialized['rows'])->toHaveCount(2);

        $quantity = xlsxTable($rows, 'Artículos por cantidad');
        expect($quantity['header'])->toBe(['Categoría', 'Tipo', 'Ubicación', 'Cantidad', 'Total del tipo', 'Stock mínimo', 'Bajo mínimo'])
            ->and($quantity['rows'])->toBe(array_map(fn (array $row) => [
                $row['asset_type']['category']['name'], $row['asset_type']['name'],
                $row['location']['name'].($row['location']['is_active'] ? '' : ' (inactiva)'),
                $row['quantity'], $row['type_total'], $row['min_stock'], $row['below_min'] ? 'Sí' : 'No',
            ], $data['quantity']))
            ->and($quantity['rows'][1][2])->toBe('Depósito viejo (inactiva)');

        expect(xlsxFields($rows, 'Totales'))->toBe([
            'Total de activos serializados' => $data['totals']['serialized'],
            'Disponibles' => $data['totals']['by_status']['available'],
            'Asignados' => $data['totals']['by_status']['assigned'],
            'En mantenimiento' => $data['totals']['by_status']['maintenance'],
            'Dados de baja' => $data['totals']['by_status']['retired'],
            'Unidades por cantidad' => $data['totals']['quantity_units'],
            'Tipos bajo mínimo' => $data['totals']['below_min_types'],
        ]);
    });

    it('por empleado (holdings, historial y totales)', function () {
        actingWithPermissions([Permission::ReportesVer->value, Permission::EmpleadosVer->value]);
        $s = deliveryScenario();
        app(CreateDelivery::class)->handle($s['employee'], DeliveryKind::Prestamo, '2026-10-30', $s['warehouse'], null, signaturePng(), [
            ['asset_id' => $s['helmet']->id],
            ['asset_type_id' => $s['gloves']->id, 'quantity' => 2],
        ], $s['user']);
        $url = "/api/reports/employees/{$s['employee']->id}?from=2026-10-01&to=2026-10-31";

        $data = $this->getJson($url)->assertOk()->json('data');
        $rows = xlsxRows(getReport("{$url}&format=xlsx")->assertOk()->getContent());

        expectSameMeta($data, $rows, 'Reporte por empleado');

        $employee = $data['employee'];
        expect(xlsxFields($rows, 'Empleado'))->toBe([
            'Nombre' => $employee['full_name'],
            'Cargo' => $employee['position'],
            'Estado' => 'Activo',
            'Tipo de documento' => $employee['document_type'],
            'Número de documento' => $employee['document_number'],
            'Teléfono' => $employee['phone'],
            'Correo' => $employee['email'],
            'Compañía' => $employee['company'],
        ]);

        $holdings = xlsxTable($rows, 'Tiene actualmente');
        expect($holdings['header'])->toBe(['Entrega', 'Tipo', 'Fecha de entrega', 'Devolución esperada', 'Estado', 'Placa', 'Artículo', 'Pendiente'])
            ->and($holdings['rows'])->toBe(array_map(fn (array $row) => [
                $row['delivery']['code'], $row['delivery']['kind_label'], bogotaDateTime($row['delivered_at']), $row['delivery']['due_on'],
                DeliveryStatus::from($row['delivery']['status'])->label(), $row['asset']['plate'] ?? null, $row['asset_type']['name'], $row['pending_quantity'],
            ], $data['holdings']))
            ->and($holdings['rows'])->toHaveCount(2);

        $history = xlsxTable($rows, 'Historial de entregas y devoluciones');
        expect($history['header'])->toBe(['Fecha', 'Evento', 'Entrega', 'Tipo', 'Placa', 'Artículo', 'Cantidad', 'Estado devuelto'])
            ->and($history['rows'])->toBe(array_map(fn (array $row) => [
                bogotaDateTime($row['date']), $row['event_label'], $row['delivery']['code'], $row['delivery']['kind_label'],
                $row['asset']['plate'] ?? null, $row['asset_type']['name'], $row['quantity'], $row['condition_label'],
            ], $data['history']));

        expect(xlsxFields($rows, 'Totales'))->toBe([
            'Unidades pendientes' => $data['totals']['pending_units'],
            'Unidades entregadas en el rango' => $data['totals']['delivered_units'],
            'Unidades devueltas en el rango' => $data['totals']['returned_units'],
        ]);
    });

    it('por activo (ficha e historial con detalle)', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = deliveryScenario();
        app(CreateDelivery::class)->handle($s['employee'], DeliveryKind::Dotacion, null, $s['warehouse'], null, signaturePng(), [
            ['asset_id' => $s['helmet']->id],
        ], $s['user']);
        $url = "/api/reports/assets/{$s['helmet']->id}";

        $data = $this->getJson($url)->assertOk()->json('data');
        $rows = xlsxRows(getReport("{$url}?format=xlsx")->assertOk()->getContent());

        expectSameMeta($data, $rows, 'Reporte por activo');

        $asset = $data['asset'];
        expect(xlsxFields($rows, 'Activo'))->toBe([
            'Placa' => $asset['plate'],
            'Serial' => $asset['serial'],
            'Marca' => $asset['brand'],
            'Modelo' => $asset['model'],
            'Estado' => $asset['status_label'],
            'Categoría' => $asset['asset_type']['category']['name'],
            'Tipo' => $asset['asset_type']['name'],
            'Ubicación' => $asset['location']['name'],
            'Poseedor' => "{$asset['holder']['full_name']} ({$asset['holder']['position']})",
            'Fecha de adquisición' => $asset['acquisition_date'],
            'Vencimiento' => $asset['expires_on'],
            'Próximo mantenimiento' => $asset['next_maintenance_on'],
        ]);

        $events = xlsxTable($rows, 'Historial');
        expect($events['header'])->toBe(['Fecha', 'Evento', 'Detalle', 'Usuario'])
            ->and($events['rows'])->toBe(array_map(fn (array $event) => [
                bogotaDateTime($event['created_at']), $event['event_label'], $event['detail'], $event['user']['name'] ?? null,
            ], $data['events']))
            ->and($events['rows'])->toHaveCount(1)
            ->and(xlsxFields($rows, 'Totales'))->toBe(['Eventos' => $data['totals']['events']]);
    });

    it('por tipo de artículo (existencias, movimientos y totales)', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = deliveryScenario(gloves: 20);
        $s['gloves']->update(['min_stock' => 30]);
        app(CreateDelivery::class)->handle($s['employee'], DeliveryKind::Dotacion, null, $s['warehouse'], null, signaturePng(), [
            ['asset_type_id' => $s['gloves']->id, 'quantity' => 4],
        ], $s['user']);
        $url = "/api/reports/asset-types/{$s['gloves']->id}?from=2026-10-19";

        $data = $this->getJson($url)->assertOk()->json('data');
        $rows = xlsxRows(getReport("{$url}&format=xlsx")->assertOk()->getContent());

        expectSameMeta($data, $rows, 'Reporte por tipo de artículo');

        expect(xlsxFields($rows, 'Tipo de artículo'))->toBe([
            'Nombre' => $data['asset_type']['name'],
            'Categoría' => $data['asset_type']['category']['name'],
            'Stock mínimo' => 30,
        ]);

        $stock = xlsxTable($rows, 'Existencias');
        expect($stock['header'])->toBe(['Ubicación', 'Cantidad'])
            ->and($stock['rows'])->toBe([['Bodega', 16]]);

        $movements = xlsxTable($rows, 'Movimientos');
        expect($movements['header'])->toBe(['Fecha', 'Clase', 'Cantidad', 'Origen', 'Destino', 'Entrega', 'Motivo', 'Usuario'])
            ->and($movements['rows'])->toBe(array_map(fn (array $m) => [
                bogotaDateTime($m['created_at']), $m['type_label'], $m['quantity'], $m['from_location']['name'] ?? null,
                $m['to_location']['name'] ?? null, $m['delivery']['code'] ?? null, $m['reason'], $m['user']['name'] ?? null,
            ], $data['movements']))
            ->and($movements['rows'])->toHaveCount(2);

        expect(xlsxFields($rows, 'Totales'))->toBe(['Existencia mostrada' => 16, 'Bajo mínimo' => 'Sí', 'Movimientos' => 2]);
    });
});

function employeeWithPersonalData(): Employee
{
    return Employee::factory()->for(Company::factory()->create(['name' => 'Compañía Norte']))->create([
        'first_name' => 'Ana', 'last_name' => 'Rojas', 'document_number' => '80123456',
        'phone' => '3001234567', 'email' => 'ana.rojas@example.com',
    ]);
}

describe('RN-022 / D-003 en los archivos', function () {
    it('sin empleados.ver el Excel y el PDF no traen documento, teléfono, correo ni compañía', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $employee = employeeWithPersonalData();
        $html = capturePdfHtml();

        $values = xlsxAllValues(getReport("/api/reports/employees/{$employee->id}?format=xlsx")->assertOk()->getContent());
        getReport("/api/reports/employees/{$employee->id}?format=pdf")->assertOk();
        $pdfHtml = $html();

        foreach (['80123456', '3001234567', 'ana.rojas@example.com', 'Compañía Norte', 'Número de documento', 'Teléfono', 'Correo'] as $secret) {
            expect($values)->not->toContain($secret)
                ->and($pdfHtml)->not->toContain($secret);
        }

        expect($values)->toContain('Ana Rojas')
            ->and($pdfHtml)->toContain('Ana Rojas');
    });

    it('con empleados.ver el Excel y el PDF sí los traen', function () {
        actingWithPermissions([Permission::ReportesVer->value, Permission::EmpleadosVer->value]);
        $employee = employeeWithPersonalData();
        $html = capturePdfHtml();

        $values = xlsxAllValues(getReport("/api/reports/employees/{$employee->id}?format=xlsx")->assertOk()->getContent());
        getReport("/api/reports/employees/{$employee->id}?format=pdf")->assertOk();
        $pdfHtml = $html();

        foreach (['80123456', '3001234567', 'ana.rojas@example.com', 'Compañía Norte'] as $value) {
            expect($values)->toContain($value)
                ->and($pdfHtml)->toContain($value);
        }
    });
});

describe('PDF (D-005)', function () {
    it('lleva el mismo encabezado, filas y totales; inventario en horizontal', function () {
        $user = actingWithPermissions([Permission::ReportesVer->value]);
        $s = exportInventoryScenario();
        $html = capturePdfHtml();

        getReport("/api/reports/inventory?location_id={$s['bodega']->id}&format=pdf")->assertOk();
        $pdfHtml = $html();

        expect($pdfHtml)->toContain('Inventario general')
            ->toContain('2026-10-19 22:00')
            ->toContain(e($user->name))
            ->toContain('Ubicación')
            ->toContain('Bodega')
            ->toContain('Activos serializados')
            ->toContain('Artículos por cantidad')
            ->toContain('Tipos bajo mínimo')
            ->toContain('DejaVu Sans')
            ->toContain('landscape')
            ->not->toContain('<script');
        expectOnlyBrandImages($pdfHtml);
    });

    it('las fichas van en vertical y escapan el HTML de los datos', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        useBrandLogo();
        $asset = Asset::factory()->create(['brand' => '<b>MSA</b><img src="http://x">']);
        $html = capturePdfHtml();

        getReport("/api/reports/assets/{$asset->id}?format=pdf")->assertOk();
        $pdfHtml = $html();

        expect($pdfHtml)->toContain('portrait')
            ->toContain('&lt;b&gt;MSA&lt;/b&gt;&lt;img src=&quot;http://x&quot;&gt;')
            ->not->toContain('src="http://x"');
        // Solo el logo y la marca de agua (RN-041).
        expect(expectOnlyBrandImages($pdfHtml))->toBe(2);
    });

    it('RN-041: lleva el logo arriba a la derecha y la marca de agua, incrustados como data:image/png', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $png = useBrandLogo();
        $html = capturePdfHtml();

        $pdf = getReport('/api/reports/inventory?format=pdf')->assertOk()->getContent();
        $pdfHtml = $html();

        $dataUri = 'data:image/png;base64,'.base64_encode($png);
        expect(expectOnlyBrandImages($pdfHtml))->toBe(2)
            ->and($pdfHtml)->toContain('class="brand-logo" src="'.$dataUri.'"')
            ->and($pdfHtml)->toContain('class="brand-watermark" src="'.$dataUri.'"')
            ->and(pdfImageCount($pdf))->toBeGreaterThanOrEqual(1);
    });

    it('RN-041: sin el archivo del logo el PDF se genera igual, sin imágenes', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        withoutBrandLogo();
        $html = capturePdfHtml();

        $pdf = getReport('/api/reports/inventory?format=pdf')->assertOk()->getContent();

        expect($pdf)->toStartWith('%PDF-')
            ->and(pdfImgTags($html()))->toBe([])
            ->and(pdfImageCount($pdf))->toBe(0);
    });
});

describe('E-8: inyección de fórmulas en Excel', function () {
    it('las celdas que empiezan por =, +, - o @ quedan como texto (sin fórmula), con prefijo de comilla', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $names = ['=HYPERLINK("http://x","clic")', '+SUM(1,2)', '-2+3', '@SUM(A1)'];
        $category = AssetCategory::factory()->create(['name' => 'Categoría normal']);
        foreach ($names as $name) {
            $type = AssetType::factory()->for($category, 'category')->create(['name' => $name, 'tracking_mode' => TrackingMode::Serialized]);
            Asset::factory()->for($type, 'assetType')->create();
        }

        $binary = getReport('/api/reports/inventory?format=xlsx')->assertOk()->getContent();
        $sheet = loadXlsx($binary)->getSheet(0);

        $found = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (in_array($cell->getValue(), $names, true)) {
                    $found[] = $cell->getValue();
                    expect($cell->getDataType())->toBe('s')
                        ->and($cell->getStyle()->getQuotePrefix())->toBeTrue();
                }
                if ($cell->getValue() === 'Categoría normal') {
                    expect($cell->getStyle()->getQuotePrefix())->toBeFalse();
                }
            }
        }

        expect($found)->toEqualCanonicalizing($names);
        // Ninguna celda del libro es una fórmula.
        expect(xlsxAllValues($binary))->toContain('=HYPERLINK("http://x","clic")');
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                expect($cell->getDataType())->not->toBe('f');
            }
        }
    });
});
