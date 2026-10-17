<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\LocksAsset;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;

/**
 * Traslado de un activo (US2, D-001): no aplica a activos Asignados (se
 * devuelven en Entregas) ni Dados de baja (409). Registra
 * "location_changed"; trasladar a la misma ubicación no hace nada.
 */
class ChangeAssetLocation
{
    use LocksAsset;

    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @throws AssetStateException
     */
    public function handle(Asset $asset, Location $location, User $user): Asset
    {
        $this->withLockedAsset($asset, function (Asset $locked) use ($location, $user) {
            match ($locked->status) {
                AssetStatus::Assigned => throw new AssetStateException(AssetStateException::ASSIGNED_MESSAGE),
                AssetStatus::Retired => throw new AssetStateException(AssetStateException::RETIRED_MESSAGE),
                default => null,
            };

            $from = $locked->location_id;

            if ($from === $location->id) {
                return;
            }

            $locked->forceFill(['location_id' => $location->id])->save();

            $this->recordEvent->handle($locked, AssetEventType::LocationChanged, $user, [
                'from_location_id' => $from,
                'to_location_id' => $location->id,
            ]);
        });

        return $asset;
    }
}
