<?php

/*
 * Apoyo de RecordStockMovementConcurrencyTest (SC-001, D-001): registra una
 * salida con RecordStockMovement en un proceso PHP aparte, con su propia
 * conexión a la base de datos de pruebas (DB_DATABASE llega por el entorno).
 *
 * Uso: php tests/Support/concurrent-exit.php <asset_type_id> <location_id> <cantidad> <user_id>
 * Imprime "ok" si la salida pasó o "rejected" si se rechazó por existencia.
 */

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\MovementType;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $assetTypeId, $locationId, $quantity, $userId] = $argv;

try {
    $app->make(RecordStockMovement::class)->handle(
        MovementType::Exit,
        AssetType::query()->findOrFail($assetTypeId),
        (int) $quantity,
        Location::query()->findOrFail($locationId),
        null,
        'Prueba de concurrencia',
        User::query()->findOrFail($userId),
    );

    echo 'ok';
} catch (ValidationException) {
    echo 'rejected';
}
