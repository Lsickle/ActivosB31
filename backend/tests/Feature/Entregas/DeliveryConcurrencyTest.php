<?php

use App\Models\Delivery;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * T004 / D-003 / SC-002: dos entregas simultáneas del mismo activo → solo
 * una pasa; el activo no queda asignado a dos empleados.
 *
 * Como RecordStockMovementConcurrencyTest (006): los datos se confirman con
 * una conexión aparte (la transacción de RefreshDatabase los ocultaría), las
 * dos entregas corren en procesos PHP distintos
 * (tests/Support/concurrent-delivery.php) y una tercera conexión bloquea la
 * fila del activo hasta que ambos procesos esperan el bloqueo.
 */
function deliveryConcurrencyConnection(string $name): Connection
{
    config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);

    return DB::connection($name);
}

/**
 * @return array<string, int>
 */
function commitDeliveryConcurrencyFixtures(Connection $db): array
{
    $suffix = Str::lower(Str::random(10));
    $now = now();
    $ids = [];

    $ids['role'] = $db->table('roles')->insertGetId(['name' => "Rol entregas {$suffix}", 'created_at' => $now, 'updated_at' => $now]);
    $ids['user'] = $db->table('users')->insertGetId([
        'name' => 'Usuario entregas', 'username' => "entregas_{$suffix}", 'password' => 'x',
        'role_id' => $ids['role'], 'created_at' => $now, 'updated_at' => $now,
    ]);
    $ids['position'] = $db->table('positions')->insertGetId(['name' => "Cargo {$suffix}", 'created_at' => $now, 'updated_at' => $now]);
    foreach (['employee_a', 'employee_b'] as $index => $key) {
        $ids[$key] = $db->table('employees')->insertGetId([
            'document_type' => 'CC', 'document_number' => strtoupper($suffix).$index, 'first_name' => 'Bombero', 'last_name' => $key,
            'position_id' => $ids['position'], 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    $ids['category'] = $db->table('asset_categories')->insertGetId(['name' => "Categoría {$suffix}", 'created_at' => $now, 'updated_at' => $now]);
    $ids['type'] = $db->table('asset_types')->insertGetId([
        'asset_category_id' => $ids['category'], 'name' => "Casco {$suffix}", 'tracking_mode' => 'serialized',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $ids['location_type'] = $db->table('location_types')->insertGetId(['name' => "Tipo {$suffix}", 'created_at' => $now, 'updated_at' => $now]);
    $ids['location'] = $db->table('locations')->insertGetId([
        'location_type_id' => $ids['location_type'], 'name' => "Bodega {$suffix}", 'created_at' => $now, 'updated_at' => $now,
    ]);
    $ids['asset'] = $db->table('assets')->insertGetId([
        'asset_type_id' => $ids['type'], 'plate' => "CONC-{$suffix}", 'location_id' => $ids['location'],
        'created_at' => $now, 'updated_at' => $now,
    ]);

    return $ids;
}

/**
 * @param  array<string, int>  $ids
 */
function removeDeliveryConcurrencyFixtures(Connection $db, array $ids): void
{
    $deliveryIds = $db->table('deliveries')->where('from_location_id', $ids['location'])->pluck('id')->all();

    foreach ($deliveryIds as $deliveryId) {
        Storage::disk(config('filesystems.signatures_disk'))->delete(Delivery::signaturePathFor($deliveryId));
    }

    $db->transaction(function () use ($db, $ids, $deliveryIds) {
        // Historial y entregas son de solo inserción: el dueño de las tablas
        // desactiva los triggers solo dentro de esta transacción de limpieza.
        $db->statement('ALTER TABLE asset_events DISABLE TRIGGER asset_events_immutable_rows');
        $db->statement('ALTER TABLE deliveries DISABLE TRIGGER deliveries_immutable_rows');
        $db->statement('ALTER TABLE delivery_items DISABLE TRIGGER delivery_items_guard_rows');
        $db->table('asset_events')->where('asset_id', $ids['asset'])->delete();
        $db->table('delivery_items')->whereIn('delivery_id', $deliveryIds)->delete();
        $db->table('deliveries')->whereIn('id', $deliveryIds)->delete();
        $db->statement('ALTER TABLE delivery_items ENABLE TRIGGER delivery_items_guard_rows');
        $db->statement('ALTER TABLE deliveries ENABLE TRIGGER deliveries_immutable_rows');
        $db->statement('ALTER TABLE asset_events ENABLE TRIGGER asset_events_immutable_rows');

        $db->table('assets')->where('id', $ids['asset'])->delete();
        $db->table('locations')->where('id', $ids['location'])->delete();
        $db->table('location_types')->where('id', $ids['location_type'])->delete();
        $db->table('asset_types')->where('id', $ids['type'])->delete();
        $db->table('asset_categories')->where('id', $ids['category'])->delete();
        $db->table('employees')->whereIn('id', [$ids['employee_a'], $ids['employee_b']])->delete();
        $db->table('positions')->where('id', $ids['position'])->delete();
        $db->table('users')->where('id', $ids['user'])->delete();
        $db->table('roles')->where('id', $ids['role'])->delete();
    });
}

it('D-003 / SC-002: dos entregas simultáneas del mismo activo: solo una pasa', function () {
    $db = deliveryConcurrencyConnection('delivery_concurrency');
    $locker = deliveryConcurrencyConnection('delivery_concurrency_locker');
    $ids = commitDeliveryConcurrencyFixtures($db);
    $processes = [];
    // El disco es el local real (los procesos hijos no ven Storage::fake) y puede
    // tener firmas del entorno de desarrollo: solo cuentan las creadas aquí.
    $signaturesBefore = Storage::disk(config('filesystems.signatures_disk'))->files('signatures');

    try {
        $locker->beginTransaction();
        $locker->select('select id from assets where id = ? for update', [$ids['asset']]);

        foreach (['employee_a', 'employee_b'] as $employee) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/concurrent-delivery.php'), $ids[$employee], $ids['asset'], $ids['location'], $ids['user']],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => $db->getDatabaseName()],
                timeout: 60,
            );
            $process->start();
            $processes[] = $process;
        }

        $deadline = microtime(true) + 30;
        while ((int) $db->scalar(
            "select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"
        ) !== 2 && ! collect($processes)->contains(fn (Process $process) => ! $process->isRunning())) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Los dos procesos no llegaron a esperar el bloqueo.');
            }

            usleep(50_000);
        }

        $locker->commit();

        $outputs = collect($processes)->map(function (Process $process) {
            $process->wait();

            return trim($process->getOutput()) ?: $process->getErrorOutput();
        });

        $accepted = $outputs->filter(fn (string $output) => str_starts_with($output, 'ok:'));
        $asset = $db->table('assets')->where('id', $ids['asset'])->first();
        $deliveryId = (int) Str::after((string) $accepted->first(), 'ok:');

        expect($outputs->sort()->values()->map(fn (string $output) => Str::before($output, ':'))->all())->toBe(['ok', 'rejected'])
            ->and($asset->status)->toBe('assigned')
            ->and($db->table('deliveries')->where('from_location_id', $ids['location'])->count())->toBe(1)
            ->and($db->table('delivery_items')->where('asset_id', $ids['asset'])->count())->toBe(1)
            ->and($db->table('asset_events')->where('asset_id', $ids['asset'])->where('event', 'delivered')->count())->toBe(1)
            ->and($db->table('deliveries')->where('id', $deliveryId)->value('employee_id'))->toBe($asset->holder_employee_id)
            // La entrega rechazada no dejó firma huérfana: solo existe la de la aceptada.
            ->and(collect(Storage::disk(config('filesystems.signatures_disk'))->files('signatures'))
                ->diff($signaturesBefore)
                ->filter(fn (string $path) => in_array($path, array_map(Delivery::signaturePathFor(...), range($deliveryId - 1, $deliveryId + 1)), true))
                ->values()->all())->toBe([Delivery::signaturePathFor($deliveryId)]);
    } finally {
        foreach ($processes as $process) {
            $process->stop(0);
        }

        if ($locker->transactionLevel() > 0) {
            $locker->rollBack();
        }

        removeDeliveryConcurrencyFixtures($db, $ids);
        DB::purge('delivery_concurrency');
        DB::purge('delivery_concurrency_locker');
    }
});
