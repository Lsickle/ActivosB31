<?php

namespace App\Reports;

use App\Models\AssetType;
use App\Models\Delivery;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * US3 (008): reporte por artículo de un tipo por cantidad: existencias
 * actuales por ubicación (no dependen del rango) y movimientos de inventario
 * (006) del rango (RN-033), más recientes primero. Con ubicación, se limitan
 * las existencias a ella y los movimientos a los que la tienen como origen o
 * destino; bajo mínimo se calcula siempre sobre el total del tipo en todas
 * las ubicaciones (RN-028).
 */
class AssetTypeReport extends Report
{
    public function __construct(
        User $user,
        private readonly AssetType $assetType,
        private readonly DateRange $range,
        private readonly ?Location $location = null,
    ) {
        parent::__construct($user);
    }

    public function title(): string
    {
        return 'Reporte por tipo de artículo';
    }

    public function fileBaseName(): string
    {
        return 'reporte-tipo';
    }

    protected function filters(): array
    {
        return [
            ...($this->location ? [['label' => 'Ubicación', 'value' => $this->location->name]] : []),
            ...$this->range->filters(),
        ];
    }

    protected function body(): array
    {
        $type = $this->assetType->loadMissing('category:id,name');

        $stock = StockLevel::query()
            ->select('stock_levels.*')
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->where('stock_levels.asset_type_id', $type->id)
            ->where('stock_levels.quantity', '>', 0)
            ->when($this->location, fn (Builder $query) => $query->where('stock_levels.location_id', $this->location->id))
            ->with('location:id,name,is_active')
            ->orderBy('locations.name')
            ->orderBy('locations.id')
            ->get()
            ->map(fn (StockLevel $level) => [
                'location' => $level->location->only(['id', 'name', 'is_active']),
                'quantity' => $level->quantity,
            ])
            ->values()
            ->all();

        $movements = InventoryMovement::query()
            ->where('asset_type_id', $type->id)
            ->tap(fn ($query) => $this->range->apply($query, 'created_at'))
            ->when($this->location, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('from_location_id', $this->location->id)
                ->orWhere('to_location_id', $this->location->id)))
            ->with(['fromLocation:id,name', 'toLocation:id,name', 'user:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $deliveryCodes = Delivery::query()
            ->whereIn('id', $movements->pluck('delivery_id')->filter()->unique())
            ->pluck('code', 'id');

        $typeTotal = (int) StockLevel::query()->where('asset_type_id', $type->id)->sum('quantity');

        return [
            'asset_type' => [
                'id' => $type->id,
                'name' => $type->name,
                'category' => $type->category->only(['id', 'name']),
                'min_stock' => $type->min_stock,
            ],
            'stock' => $stock,
            'movements' => $movements->map(fn (InventoryMovement $movement) => [
                'id' => $movement->id,
                'type' => $movement->type->value,
                'type_label' => $movement->type->label(),
                'quantity' => $movement->quantity,
                'from_location' => $movement->fromLocation?->only(['id', 'name']),
                'to_location' => $movement->toLocation?->only(['id', 'name']),
                'reason' => $movement->reason,
                'delivery' => $movement->delivery_id === null ? null : ['id' => $movement->delivery_id, 'code' => $deliveryCodes[$movement->delivery_id] ?? null],
                'user' => $movement->user?->only(['id', 'name']),
                'created_at' => $movement->created_at,
            ])->values()->all(),
            'totals' => [
                'stock_total' => collect($stock)->sum('quantity'),
                'below_min' => $type->min_stock !== null && $typeTotal < $type->min_stock,
                'movements' => $movements->count(),
            ],
        ];
    }

    protected function sectionsFrom(array $data): array
    {
        $type = $data['asset_type'];

        return [
            ReportSection::fields('Tipo de artículo', [
                'Nombre' => $type['name'],
                'Categoría' => $type['category']['name'],
                'Stock mínimo' => $type['min_stock'],
            ]),
            ReportSection::table(
                'Existencias',
                ['Ubicación', 'Cantidad'],
                array_map(fn (array $row) => [
                    $row['location']['name'].($row['location']['is_active'] ? '' : ' (inactiva)'),
                    $row['quantity'],
                ], $data['stock']),
            ),
            ReportSection::table(
                'Movimientos',
                ['Fecha', 'Clase', 'Cantidad', 'Origen', 'Destino', 'Entrega', 'Motivo', 'Usuario'],
                array_map(fn (array $movement) => [
                    self::localDateTime($movement['created_at']),
                    $movement['type_label'],
                    $movement['quantity'],
                    $movement['from_location']['name'] ?? null,
                    $movement['to_location']['name'] ?? null,
                    $movement['delivery']['code'] ?? null,
                    $movement['reason'],
                    $movement['user']['name'] ?? null,
                ], $data['movements']),
            ),
            ReportSection::fields('Totales', [
                'Existencia mostrada' => $data['totals']['stock_total'],
                'Bajo mínimo' => self::yesNo($data['totals']['below_min']),
                'Movimientos' => $data['totals']['movements'],
            ]),
        ];
    }
}
