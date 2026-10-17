<?php

namespace App\Actions\Activos;

use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Alta de un activo serializado (US1-1): nace Disponible (RN-005) en la
 * ubicación indicada y registra el evento "created" (D-002). La foto, si
 * viene, se re-codifica antes de abrir la transacción (una imagen inválida no
 * deja un activo a medias) y se guarda dentro de ella (la ruta lleva el id).
 */
class CreateAsset
{
    public function __construct(
        private RecordAssetEvent $recordEvent,
        private StoreAssetPhoto $storePhoto,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, User $user, ?UploadedFile $photo = null): Asset
    {
        $encodedPhoto = $photo === null ? null : $this->storePhoto->reencode($photo);

        return DB::transaction(function () use ($data, $user, $encodedPhoto) {
            $asset = new Asset($data);
            $asset->forceFill([
                'status' => AssetStatus::Available,
                'location_id' => $data['location_id'] ?? null,
            ])->save();

            $this->recordEvent->handle($asset, AssetEventType::Created, $user, [
                'to_status' => AssetStatus::Available,
                'to_location_id' => $asset->location_id,
            ]);

            if ($encodedPhoto !== null) {
                $this->storePhoto->storeEncoded($asset, $encodedPhoto, $user, recordEvent: false);
            }

            return $asset;
        });
    }
}
