<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\LocksAsset;
use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Quita la foto de un activo (FR-008): borra el archivo del disco privado
 * cuando la transacción confirma y deja constancia en el historial.
 *
 * B-1 (revisión de seguridad de 005): la foto se lee y se quita con la fila
 * bloqueada; un fallo al borrar el archivo se reporta sin fallar.
 */
class RemoveAssetPhoto
{
    use LocksAsset;

    public function __construct(private RecordAssetEvent $recordEvent) {}

    public function handle(Asset $asset, User $user): Asset
    {
        $previous = $this->withLockedAsset($asset, function (Asset $locked) use ($user) {
            $previous = $locked->photo_path;

            if ($previous !== null) {
                $locked->forceFill(['photo_path' => null])->save();
                $this->recordEvent->handle($locked, AssetEventType::Updated, $user, ['notes' => 'Foto eliminada.']);
            }

            return $previous;
        });

        if ($previous !== null) {
            DB::afterCommit(fn () => StoreAssetPhoto::deleteQuietly(Asset::photoDisk(), $previous));
        }

        return $asset;
    }
}
