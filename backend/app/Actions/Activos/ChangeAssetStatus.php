<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\LocksAsset;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\User;

/**
 * Cambio manual de estado (US2, D-001): Disponible ⇄ En mantenimiento y
 * Disponible / En mantenimiento → Dado de baja (definitivo, con motivo; el
 * evento es "retired"). Un activo Asignado o Dado de baja responde 409.
 */
class ChangeAssetStatus
{
    use LocksAsset;

    public const string SAME_STATUS_MESSAGE = 'El activo ya está en ese estado.';

    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @throws AssetStateException
     */
    public function handle(Asset $asset, AssetStatus $target, User $user, ?string $reason = null): Asset
    {
        $this->withLockedAsset($asset, function (Asset $locked) use ($target, $user, $reason) {
            $current = $locked->status;

            if ($current === AssetStatus::Assigned) {
                throw new AssetStateException(AssetStateException::ASSIGNED_MESSAGE);
            }

            if ($current === AssetStatus::Retired) {
                throw new AssetStateException(AssetStateException::RETIRED_MESSAGE);
            }

            if ($current === $target) {
                throw new AssetStateException(self::SAME_STATUS_MESSAGE);
            }

            if (! $current->canTransitionManuallyTo($target)) {
                throw new AssetStateException('No se puede cambiar de '.$current->label().' a '.$target->label().'.');
            }

            $locked->forceFill(['status' => $target])->save();

            $this->recordEvent->handle(
                $locked,
                $target === AssetStatus::Retired ? AssetEventType::Retired : AssetEventType::StatusChanged,
                $user,
                ['from_status' => $current, 'to_status' => $target, 'notes' => $reason],
            );
        });

        return $asset;
    }
}
