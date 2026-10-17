<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\DiffsAttributes;
use App\Actions\Activos\Concerns\LocksAsset;
use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\User;

/**
 * Edición de los datos de un activo (FR-001). Registra "updated" con los
 * campos cambiados ({campo:{from,to}}, D-002) solo si algo cambió. Un activo
 * dado de baja también se puede editar (no cambia su estado).
 *
 * B-2 (revisión de seguridad de 005): los cambios se aplican y se comparan
 * sobre la fila bloqueada, no sobre la instancia recibida (que puede estar
 * desactualizada): el historial refleja lo que realmente cambió.
 */
class UpdateAsset
{
    use DiffsAttributes;
    use LocksAsset;

    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @param  array<string, mixed>  $data  solo campos de Asset::$fillable
     */
    public function handle(Asset $asset, array $data, User $user): Asset
    {
        $this->withLockedAsset($asset, function (Asset $locked) use ($data, $user) {
            $locked->fill($data);
            $changes = $this->dirtyChanges($locked);

            if ($changes === []) {
                return;
            }

            $locked->save();
            $this->recordEvent->handle($locked, AssetEventType::Updated, $user, ['changes' => $changes]);
        });

        return $asset;
    }
}
