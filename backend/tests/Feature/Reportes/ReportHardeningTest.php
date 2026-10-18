<?php

use App\Actions\RecordSecurityEvent;
use App\Enums\Permission;
use App\Enums\SecurityEventType;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\SecurityEvent;
use App\Reports\Export\ReportResponder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/reports.php';

/**
 * Correcciones de la revisión de seguridad de 008 (R-1, R-2, R-3, R-5).
 * La configuración de dompdf (R-7) está en ReportExportConfigTest.
 */
beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Crea $types tipos serializados con un activo cada uno: el inventario
 * general tiene entonces $types filas de "Activos serializados".
 */
function serializedInventoryRows(int $types): void
{
    $category = AssetCategory::factory()->create();

    for ($i = 0; $i < $types; $i++) {
        $type = AssetType::factory()->for($category, 'category')->create(['tracking_mode' => TrackingMode::Serialized]);
        Asset::factory()->for($type, 'assetType')->create();
    }
}

describe('R-1: formato y filtros solo desde la query', function () {
    it('un GET con cuerpo JSON {"format":"pdf"} responde el JSON del reporte, no un PDF', function () {
        actingWithPermissions([Permission::ReportesVer->value]);

        $response = $this->json('GET', '/api/reports/inventory', ['format' => 'pdf'])->assertOk();

        expect($response->headers->get('Content-Type'))->toContain('application/json')
            ->and($response->headers->get('Content-Disposition'))->toBeNull()
            ->and($response->json('data.serialized'))->toBeArray();
    });

    it('los filtros del cuerpo tampoco se validan ni se aplican', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        serializedInventoryRows(2);

        $data = $this->json('GET', '/api/reports/inventory', ['asset_type_id' => 999999, 'status' => 'xyz'])
            ->assertOk()
            ->json('data');

        expect($data['filters'])->toBe([])
            ->and($data['serialized'])->toHaveCount(2);
    });

    it('las descargas por cuerpo no evaden el límite: 10 por query pasan y la 11.ª da 429', function () {
        actingWithPermissions([Permission::ReportesVer->value]);

        for ($i = 0; $i < 10; $i++) {
            $this->json('GET', '/api/reports/inventory', ['format' => 'xlsx'])->assertOk()->assertJsonStructure(['data']);
        }

        for ($i = 0; $i < 10; $i++) {
            getReport('/api/reports/inventory?format=xlsx')->assertOk();
        }

        getReport('/api/reports/inventory?format=pdf')->assertTooManyRequests()->assertJsonStructure(['message']);
    });
});

describe('R-5: ids no numéricos en la ruta → 404', function () {
    it('responde 404 sin llegar a la consulta', function (string $method, string $uri) {
        actingAsRole('admin');

        $this->json($method, $uri)->assertNotFound()->assertJson(['message' => 'Recurso no encontrado.']);
    })->with([
        'reporte por activo' => ['GET', '/api/reports/assets/abc'],
        'reporte por activo con signo' => ['GET', '/api/reports/assets/-1'],
        'reporte por empleado' => ['GET', '/api/reports/employees/abc'],
        'reporte por tipo' => ['GET', '/api/reports/asset-types/1e3'],
        'entrega' => ['GET', '/api/deliveries/abc'],
        'devolución' => ['POST', '/api/deliveries/abc/returns'],
        'tenencias' => ['GET', '/api/employees/abc/holdings'],
        'empleado' => ['GET', '/api/employees/abc'],
        'estado de empleado' => ['PATCH', '/api/employees/abc/status'],
        'conteo' => ['GET', '/api/inventory/counts/abc'],
        'usuario' => ['GET', '/api/users/abc'],
        'desbloqueo' => ['POST', '/api/users/abc/unlock'],
        'rol' => ['PATCH', '/api/roles/abc'],
        'matriz de un rol' => ['PUT', '/api/roles/abc/permissions'],
        'categoría' => ['GET', '/api/asset-categories/abc'],
        'tipo de activo' => ['GET', '/api/asset-types/abc'],
        'tipo de ubicación' => ['GET', '/api/location-types/abc'],
        'ubicación' => ['GET', '/api/locations/abc'],
        'cargo' => ['GET', '/api/positions/abc'],
        'compañía' => ['GET', '/api/companies/abc'],
    ]);

    it('las rutas fijas siguen resolviendo (/employees/options, /assets/alerts, /deliveries/alerts)', function () {
        actingAsRole('admin');

        $this->getJson('/api/employees/options')->assertOk();
        $this->getJson('/api/assets/alerts')->assertOk();
        $this->getJson('/api/deliveries/alerts')->assertOk();
    });
});

describe('R-3: bitácora de exportaciones', function () {
    it('registra report_exported al descargar xlsx o pdf, no al consultar el JSON', function () {
        // metadata es jsonb: PostgreSQL reordena las claves (toEqual no exige orden).
        $user = actingWithPermissions([Permission::ReportesVer->value]);
        $asset = Asset::factory()->create();

        $this->getJson("/api/reports/assets/{$asset->id}?from=2026-10-01")->assertOk();
        expect(SecurityEvent::query()->where('event', SecurityEventType::ReportExported)->count())->toBe(0);

        getReport("/api/reports/assets/{$asset->id}?from=2026-10-01&format=pdf")->assertOk();
        getReport('/api/reports/inventory?format=xlsx')->assertOk();

        $events = SecurityEvent::query()->where('event', SecurityEventType::ReportExported)->orderBy('id')->get();

        expect($events)->toHaveCount(2)
            ->and($events[0]->actor_id)->toBe($user->id)
            ->and($events[0]->metadata)->toEqual([
                'report' => 'asset',
                'subject_id' => $asset->id,
                'format' => 'pdf',
                'filters' => ['from' => '2026-10-01'],
                'personal_data' => false,
            ])
            ->and($events[1]->metadata)->toEqual([
                'report' => 'inventory',
                'subject_id' => null,
                'format' => 'xlsx',
                'filters' => [],
                'personal_data' => false,
            ]);
    });

    it('personal_data indica si el archivo trae datos personales del empleado (RN-022)', function () {
        $employee = Employee::factory()->create();

        actingWithPermissions([Permission::ReportesVer->value]);
        getReport("/api/reports/employees/{$employee->id}?format=xlsx")->assertOk();

        actingWithPermissions([Permission::ReportesVer->value, Permission::EmpleadosVer->value]);
        getReport("/api/reports/employees/{$employee->id}?format=pdf&to=2026-10-19")->assertOk();

        $events = SecurityEvent::query()->where('event', SecurityEventType::ReportExported)->orderBy('id')->get();

        expect($events->pluck('metadata.personal_data')->all())->toBe([false, true])
            ->and($events[1]->metadata)->toMatchArray([
                'report' => 'employee',
                'subject_id' => $employee->id,
                'filters' => ['to' => '2026-10-19'],
            ]);
    });

    it('la etiqueta del evento es "Reporte exportado"', function () {
        expect(SecurityEventType::ReportExported->value)->toBe('report_exported')
            ->and(SecurityEventType::ReportExported->label())->toBe('Reporte exportado');
    });
});

describe('R-2: tope de filas de los archivos', function () {
    it('fija 2 000 filas para PDF y 20 000 para Excel', function () {
        expect(ReportResponder::PDF_MAX_ROWS)->toBe(2000)
            ->and(ReportResponder::XLSX_MAX_ROWS)->toBe(20000);
    });

    it('si el reporte supera el tope, 422 en `from` sin generar el archivo ni registrar la exportación; el JSON no tiene tope', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        serializedInventoryRows(3);
        app()->bind(ReportResponder::class, fn ($app) => new ReportResponder($app->make(RecordSecurityEvent::class), pdfMaxRows: 2, xlsxMaxRows: 3));

        getReport('/api/reports/inventory?format=pdf')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from' => 'El reporte es demasiado grande: acota el rango de fechas o los filtros.']);

        getReport('/api/reports/inventory?format=xlsx')->assertOk();

        serializedInventoryRows(1);
        getReport('/api/reports/inventory?format=xlsx')->assertUnprocessable()->assertJsonValidationErrors(['from']);
        $this->getJson('/api/reports/inventory')->assertOk()->assertJsonCount(4, 'data.serialized');

        expect(SecurityEvent::query()->where('event', SecurityEventType::ReportExported)->count())->toBe(1);
    });
});
