<?php

namespace App\Http\Resources;

use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Conteo físico (US3, D-004). `lines` solo si están cargados (el listado no
 * los incluye); `changed_since_open` solo en la respuesta del cierre.
 * Contrato: specs/006-modulo-inventario/contracts/api.md.
 *
 * @mixin InventoryCount
 */
class InventoryCountResource extends JsonResource
{
    /**
     * @var list<string>
     */
    public const array RELATIONS = ['location:id,name', 'openedBy:id,name', 'closedBy:id,name'];

    private ?bool $changedSinceOpen = null;

    public function withChangedSinceOpen(bool $changed): static
    {
        $this->changedSinceOpen = $changed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location' => $this->location->only(['id', 'name']),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'opened_by' => $this->openedBy?->only(['id', 'name']),
            'closed_by' => $this->closedBy?->only(['id', 'name']),
            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
            'changed_since_open' => $this->when($this->changedSinceOpen !== null, $this->changedSinceOpen),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (InventoryCountLine $line) => [
                'id' => $line->id,
                'asset_type' => $line->assetType?->only(['id', 'name']),
                'asset' => $line->asset?->only(['id', 'plate']),
                'expected_quantity' => $line->expected_quantity,
                'counted_quantity' => $line->counted_quantity,
                'found' => $line->found,
            ])->values()),
        ];
    }
}
