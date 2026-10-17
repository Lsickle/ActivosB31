<?php

namespace App\Actions\Activos;

use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\User;

/**
 * Registra un evento en el historial del activo (FR-004, D-002). Se llama
 * dentro de la misma transacción de la operación: si esta falla, el evento
 * no queda.
 */
class RecordAssetEvent
{
    /**
     * @param  array{from_status?: mixed, to_status?: mixed, from_location_id?: ?int, to_location_id?: ?int, employee_id?: ?int, delivery_id?: ?int, notes?: ?string, changes?: ?array<string, array{from: mixed, to: mixed}>}  $attributes
     */
    public function handle(Asset $asset, AssetEventType $type, User $user, array $attributes = []): AssetEvent
    {
        return AssetEvent::query()->create([
            ...$attributes,
            'asset_id' => $asset->getKey(),
            'event' => $type,
            'user_id' => $user->getKey(),
        ]);
    }
}
