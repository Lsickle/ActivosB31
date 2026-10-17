<?php

/*
 * Ayudas de los tests de Entregas (007). Se cargan con require_once desde
 * cada archivo de tests/Feature/Entregas (Pest define las funciones de forma
 * global: un único archivo evita redefinirlas).
 */

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\MovementType;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;

/**
 * PNG real dibujado con GD (un trazo, como el de signature_pad).
 */
function signaturePng(int $width = 400, int $height = 150): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imageline($image, 10, (int) ($height / 2), $width - 10, (int) ($height / 3), imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function signatureDataUrl(?string $binary = null, string $mime = 'image/png'): string
{
    return "data:{$mime};base64,".base64_encode($binary ?? signaturePng());
}

/**
 * PNG con solo la firma y el encabezado IHDR (dimensiones declaradas sin
 * reservar memoria para la imagen).
 */
function signaturePngHeaderOnly(int $width, int $height): string
{
    $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

    return "\x89PNG\r\n\x1A\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
}

/**
 * Escenario de US1-1: bodega con 20 guantes, un casco disponible en la bodega
 * y un empleado activo. $suffix distingue los nombres si se crean varios.
 *
 * @return array{warehouse: Location, employee: Employee, helmet: Asset, gloves: AssetType, user: User}
 */
function deliveryScenario(int $gloves = 20, string $suffix = ''): array
{
    $warehouse = Location::factory()->create(['name' => "Bodega{$suffix}"]);
    $glovesType = AssetType::factory()->create(['name' => "Guantes{$suffix}", 'tracking_mode' => TrackingMode::Quantity]);
    $stocker = User::factory()->create();

    if ($gloves > 0) {
        app(RecordStockMovement::class)->handle(MovementType::Entry, $glovesType, $gloves, null, $warehouse, null, $stocker);
    }

    return [
        'warehouse' => $warehouse,
        'employee' => Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Rojas']),
        'helmet' => Asset::factory()->at($warehouse)->create(['plate' => "CAS-001{$suffix}"]),
        'gloves' => $glovesType,
        'user' => $stocker,
    ];
}

/**
 * @param  array{warehouse: Location, employee: Employee, helmet: Asset, gloves: AssetType}  $scenario
 * @return array<string, mixed>
 */
function deliveryPayload(array $scenario, array $overrides = []): array
{
    return [
        'employee_id' => $scenario['employee']->id,
        'kind' => 'dotacion',
        'due_on' => null,
        'from_location_id' => $scenario['warehouse']->id,
        'notes' => null,
        'signature' => signatureDataUrl(),
        'items' => [
            ['asset_id' => $scenario['helmet']->id],
            ['asset_type_id' => $scenario['gloves']->id, 'quantity' => 2],
        ],
        ...$overrides,
    ];
}
