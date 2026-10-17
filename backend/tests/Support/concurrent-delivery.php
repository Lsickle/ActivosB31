<?php

/*
 * Apoyo de DeliveryConcurrencyTest (D-003, SC-002): registra una entrega de
 * un activo con CreateDelivery en un proceso PHP aparte, con su propia
 * conexión a la base de datos de pruebas (DB_DATABASE llega por el entorno).
 *
 * Uso: php tests/Support/concurrent-delivery.php <employee_id> <asset_id> <location_id> <user_id>
 * Imprime "ok:<delivery_id>" si la entrega pasó o "rejected" si se rechazó
 * por validación (activo no disponible).
 */

use App\Actions\Entregas\CreateDelivery;
use App\Enums\DeliveryKind;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $employeeId, $assetId, $locationId, $userId] = $argv;

$image = imagecreatetruecolor(200, 80);
imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
ob_start();
imagepng($image);
$signature = (string) ob_get_clean();

try {
    $delivery = $app->make(CreateDelivery::class)->handle(
        Employee::query()->findOrFail($employeeId),
        DeliveryKind::Dotacion,
        null,
        Location::query()->findOrFail($locationId),
        'Prueba de concurrencia',
        $signature,
        [['asset_id' => (int) $assetId]],
        User::query()->findOrFail($userId),
    );

    echo "ok:{$delivery->id}";
} catch (ValidationException) {
    echo 'rejected';
}
