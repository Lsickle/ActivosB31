<?php

use App\Enums\Permission;
use App\Enums\TrackingMode;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/reports.php';

/**
 * 008 — SC-001: un reporte de 1 000 filas se exporta en menos de 10 segundos
 * (Excel y PDF, generados en memoria sin colas, D-002).
 */
it('SC-001: exporta 1 000 movimientos a Excel y a PDF en menos de 10 s cada uno', function () {
    actingWithPermissions([Permission::ReportesVer->value]);
    $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);
    $location = Location::factory()->create();
    $user = User::factory()->create();

    // Movimientos de entrada insertados en bloque (la tabla es de solo inserción).
    DB::table('inventory_movements')->insert(array_map(fn (int $i) => [
        'type' => 'entry',
        'asset_type_id' => $type->id,
        'quantity' => 1 + $i % 7,
        'to_location_id' => $location->id,
        'reason' => "Compra número {$i}",
        'user_id' => $user->id,
        'created_at' => now()->subMinutes($i),
    ], range(1, 1000)));

    // Tiempo de CPU del proceso (no de reloj): la suite corre en paralelo y
    // los demás procesos no deben contar contra SC-001.
    $cpuSeconds = function (): float {
        $usage = getrusage();

        return $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6 + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
    };

    foreach (['xlsx', 'pdf'] as $format) {
        $started = $cpuSeconds();
        getReport("/api/reports/asset-types/{$type->id}?format={$format}")->assertOk();
        $seconds = $cpuSeconds() - $started;

        expect($seconds)->toBeLessThan(10.0, "{$format}: {$seconds} s");
    }

    $rows = xlsxRows(getReport("/api/reports/asset-types/{$type->id}?format=xlsx")->getContent());
    expect(xlsxTable($rows, 'Movimientos')['rows'])->toHaveCount(1000);
});
