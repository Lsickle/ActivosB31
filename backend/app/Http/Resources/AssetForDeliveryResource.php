<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * B-2 (revisión de seguridad de 007): activo Disponible para el asistente de
 * entrega, cuando el usuario llega a GET /api/assets solo con
 * entregas.gestionar (sin activos.ver). Únicamente lo que el asistente
 * necesita para elegir el activo: sin valor de adquisición, notas, marca,
 * modelo, poseedor ni mantenimientos.
 *
 * @mixin Asset
 */
class AssetForDeliveryResource extends JsonResource
{
    /**
     * @var list<string>
     */
    public const array RELATIONS = ['assetType:id,name', 'location:id,name'];

    /**
     * @return array{id: int, plate: string, serial: string|null, status: string, asset_type: array{id: int, name: string}, location: array{id: int, name: string}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'serial' => $this->serial,
            'status' => $this->status->value,
            'asset_type' => $this->assetType->only(['id', 'name']),
            'location' => $this->location?->only(['id', 'name']),
        ];
    }
}
