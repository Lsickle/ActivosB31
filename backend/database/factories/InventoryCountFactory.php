<?php

namespace Database\Factories;

use App\Enums\CountStatus;
use App\Models\InventoryCount;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Conteo abierto sin renglones. En el flujo real se abre con
 * App\Actions\Inventario\OpenInventoryCount (que crea los renglones esperados).
 * No hay factories de movimientos ni existencias: solo se crean con
 * RecordStockMovement para conservar SC-002.
 *
 * @extends Factory<InventoryCount>
 */
class InventoryCountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'location_id' => Location::factory(),
            'status' => CountStatus::Open,
            'notes' => null,
            'opened_by' => User::factory(),
            'opened_at' => now(),
        ];
    }
}
