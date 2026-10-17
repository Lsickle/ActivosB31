<?php

namespace Database\Factories;

use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ítem de una entrega, por defecto de un tipo por cantidad. Solo registra la
 * fila: no asigna el activo ni mueve existencias.
 *
 * @extends Factory<DeliveryItem>
 */
class DeliveryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'asset_id' => null,
            'asset_type_id' => AssetType::factory()->state(['tracking_mode' => TrackingMode::Quantity]),
            'quantity' => 1,
            'returned_quantity' => 0,
        ];
    }

    /**
     * RN-025: ítem serializado (1 unidad del activo).
     */
    public function forAsset(Asset $asset): static
    {
        return $this->state(fn (array $attributes) => [
            'asset_id' => $asset->id,
            'asset_type_id' => $asset->asset_type_id,
            'quantity' => 1,
        ]);
    }
}
