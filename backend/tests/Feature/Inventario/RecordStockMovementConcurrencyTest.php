<?php

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * SC-001 / US1-5 / D-001: dos salidas simultáneas que juntas superan la
 * existencia → solo una se acepta.
 *
 * La transacción de RefreshDatabase oculta los datos a otras conexiones, así
 * que este test confirma sus datos con una conexión aparte y los borra al
 * final. Las dos salidas corren en procesos PHP distintos
 * (tests/Support/concurrent-exit.php). Para forzar la carrera, una tercera
 * conexión bloquea la fila de existencia hasta que ambos procesos están
 * esperando el bloqueo; al liberarla compiten de verdad.
 */
function concurrencyConnection(string $name): Connection
{
    config(["database.connections.{$name}" => config('database.connections.'.config('database.default'))]);

    return DB::connection($name);
}

/**
 * @return array{role: int, user: int, category: int, type: int, location_type: int, location: int}
 */
function commitConcurrencyFixtures(Connection $db, int $initialStock): array
{
    $suffix = Str::lower(Str::random(10));
    $now = now();
    $ids = [];

    $ids['role'] = $db->table('roles')->insertGetId(['name' => "Rol concurrencia {$suffix}", 'created_at' => $now, 'updated_at' => $now]);
    $ids['user'] = $db->table('users')->insertGetId([
        'name' => 'Usuario concurrencia', 'username' => "concurrencia_{$suffix}", 'password' => 'x',
        'role_id' => $ids['role'], 'created_at' => $now, 'updated_at' => $now,
    ]);
    $ids['category'] = $db->table('asset_categories')->insertGetId(['name' => "Categoría {$suffix}", 'created_at' => $now, 'updated_at' => $now]);
    $ids['type'] = $db->table('asset_types')->insertGetId([
        'asset_category_id' => $ids['category'], 'name' => "Guantes {$suffix}", 'tracking_mode' => 'quantity',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $ids['location_type'] = $db->table('location_types')->insertGetId(['name' => "Tipo {$suffix}", 'created_at' => $now, 'updated_at' => $now]);
    $ids['location'] = $db->table('locations')->insertGetId([
        'location_type_id' => $ids['location_type'], 'name' => "Bodega {$suffix}", 'created_at' => $now, 'updated_at' => $now,
    ]);

    $db->table('stock_levels')->insert(['asset_type_id' => $ids['type'], 'location_id' => $ids['location'], 'quantity' => $initialStock]);
    $db->table('inventory_movements')->insert([
        'type' => 'entry', 'asset_type_id' => $ids['type'], 'quantity' => $initialStock,
        'to_location_id' => $ids['location'], 'user_id' => $ids['user'],
    ]);

    return $ids;
}

/**
 * @param  array{role: int, user: int, category: int, type: int, location_type: int, location: int}  $ids
 */
function removeConcurrencyFixtures(Connection $db, array $ids): void
{
    $db->transaction(function () use ($db, $ids) {
        // Los movimientos son de solo inserción: el dueño de la tabla
        // desactiva el trigger solo dentro de esta transacción de limpieza.
        $db->statement('ALTER TABLE inventory_movements DISABLE TRIGGER inventory_movements_immutable_rows');
        $db->table('inventory_movements')->where('asset_type_id', $ids['type'])->delete();
        $db->statement('ALTER TABLE inventory_movements ENABLE TRIGGER inventory_movements_immutable_rows');

        $db->table('stock_levels')->where('asset_type_id', $ids['type'])->delete();
        $db->table('asset_types')->where('id', $ids['type'])->delete();
        $db->table('asset_categories')->where('id', $ids['category'])->delete();
        $db->table('locations')->where('id', $ids['location'])->delete();
        $db->table('location_types')->where('id', $ids['location_type'])->delete();
        $db->table('users')->where('id', $ids['user'])->delete();
        $db->table('roles')->where('id', $ids['role'])->delete();
    });
}

function waitUntil(Closure $condition, float $seconds, string $message): void
{
    $deadline = microtime(true) + $seconds;

    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException($message);
        }

        usleep(50_000);
    }
}

it('SC-001 / US1-5: dos salidas simultáneas que juntas superan la existencia: solo una pasa', function () {
    $db = concurrencyConnection('inventory_concurrency');
    $locker = concurrencyConnection('inventory_concurrency_locker');
    $ids = commitConcurrencyFixtures($db, 10);
    $processes = [];

    try {
        $locker->beginTransaction();
        $locker->select('select quantity from stock_levels where asset_type_id = ? and location_id = ? for update', [$ids['type'], $ids['location']]);

        foreach (range(1, 2) as $index) {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Support/concurrent-exit.php'), $ids['type'], $ids['location'], 7, $ids['user']],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_DATABASE' => $db->getDatabaseName()],
                timeout: 60,
            );
            $process->start();
            $processes[] = $process;
        }

        waitUntil(
            fn () => (int) $db->scalar(
                "select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"
            ) === 2 || collect($processes)->contains(fn (Process $process) => ! $process->isRunning()),
            30,
            'Los dos procesos no llegaron a esperar el bloqueo.',
        );

        $locker->commit();

        $outputs = collect($processes)->map(function (Process $process) {
            $process->wait();

            return trim($process->getOutput()) ?: $process->getErrorOutput();
        })->sort()->values()->all();

        expect($outputs)->toBe(['ok', 'rejected'])
            ->and((int) $db->table('stock_levels')->where('asset_type_id', $ids['type'])->value('quantity'))->toBe(3)
            ->and($db->table('inventory_movements')->where('asset_type_id', $ids['type'])->where('type', 'exit')->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            $process->stop(0);
        }

        if ($locker->transactionLevel() > 0) {
            $locker->rollBack();
        }

        removeConcurrencyFixtures($db, $ids);
        DB::purge('inventory_concurrency');
        DB::purge('inventory_concurrency_locker');
    }
});
