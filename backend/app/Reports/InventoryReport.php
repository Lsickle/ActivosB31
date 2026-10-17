<?php

namespace App\Reports;

use App\Enums\AssetStatus;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\User;
use App\Queries\StockAlerts;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * US1 (008): inventario general. Serializados agrupados por tipo, ubicación
 * y estado; artículos por cantidad por tipo y ubicación con el total del tipo
 * y el stock mínimo (RN-028), con la misma regla que GET /api/inventory/stock
 * de 006 (solo existencias > 0; total y bajo mínimo sobre todas las
 * ubicaciones), así los totales coinciden con Inventario (SC-002).
 *
 * El filtro de estado solo aplica a los serializados.
 */
class InventoryReport extends Report
{
    public function __construct(
        User $user,
        private readonly ?AssetCategory $category = null,
        private readonly ?AssetType $assetType = null,
        private readonly ?Location $location = null,
        private readonly ?AssetStatus $status = null,
    ) {
        parent::__construct($user);
    }

    public function title(): string
    {
        return 'Inventario general';
    }

    public function fileBaseName(): string
    {
        return 'inventario';
    }

    /**
     * D-005: carta horizontal.
     */
    public function orientation(): string
    {
        return 'landscape';
    }

    protected function filters(): array
    {
        return array_values(array_filter([
            $this->category ? ['label' => 'Categoría', 'value' => $this->category->name] : null,
            $this->assetType ? ['label' => 'Tipo', 'value' => $this->assetType->name] : null,
            $this->location ? ['label' => 'Ubicación', 'value' => $this->location->name] : null,
            $this->status ? ['label' => 'Estado', 'value' => $this->status->label()] : null,
        ]));
    }

    protected function body(): array
    {
        $serialized = $this->serialized();
        $quantity = $this->quantity();

        $byStatus = [];
        foreach (AssetStatus::cases() as $status) {
            $byStatus[$status->value] = collect($serialized)->where('status', $status->value)->sum('count');
        }

        return [
            'serialized' => $serialized,
            'quantity' => $quantity,
            'totals' => [
                'serialized' => array_sum($byStatus),
                'by_status' => $byStatus,
                'quantity_units' => collect($quantity)->sum('quantity'),
                'below_min_types' => collect($quantity)->where('below_min', true)->pluck('asset_type.id')->unique()->count(),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serialized(): array
    {
        $statusOrder = collect(AssetStatus::cases())
            ->map(fn (AssetStatus $status, int $index) => "when '{$status->value}' then {$index}")
            ->implode(' ');

        return DB::table('assets')
            ->join('asset_types', 'asset_types.id', '=', 'assets.asset_type_id')
            ->join('asset_categories', 'asset_categories.id', '=', 'asset_types.asset_category_id')
            ->leftJoin('locations', 'locations.id', '=', 'assets.location_id')
            ->select('asset_types.id as type_id', 'asset_types.name as type_name')
            ->addSelect('asset_categories.id as category_id', 'asset_categories.name as category_name')
            ->addSelect('locations.id as location_id', 'locations.name as location_name', 'assets.status')
            ->selectRaw('count(*) as count')
            ->tap(fn (Builder $query) => $this->applyCatalogFilters($query, 'assets.asset_type_id', 'assets.location_id'))
            ->when($this->status, fn (Builder $query) => $query->where('assets.status', $this->status->value))
            ->groupBy('asset_types.id', 'asset_categories.id', 'locations.id', 'assets.status')
            ->orderBy('asset_categories.name')
            ->orderBy('asset_categories.id')
            ->orderBy('asset_types.name')
            ->orderBy('asset_types.id')
            ->orderByRaw('locations.name asc nulls last')
            ->orderBy('locations.id')
            ->orderByRaw("case assets.status {$statusOrder} end")
            ->get()
            ->map(fn (object $row) => [
                'asset_type' => self::typeRef($row),
                'location' => $row->location_id === null ? null : ['id' => $row->location_id, 'name' => $row->location_name],
                'status' => $row->status,
                'status_label' => AssetStatus::from($row->status)->label(),
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quantity(): array
    {
        return DB::table('stock_levels')
            ->joinSub(StockAlerts::typeTotals(), 'totals', 'totals.asset_type_id', '=', 'stock_levels.asset_type_id')
            ->join('asset_types', 'asset_types.id', '=', 'stock_levels.asset_type_id')
            ->join('asset_categories', 'asset_categories.id', '=', 'asset_types.asset_category_id')
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->select('asset_types.id as type_id', 'asset_types.name as type_name', 'asset_types.min_stock')
            ->addSelect('asset_categories.id as category_id', 'asset_categories.name as category_name')
            ->addSelect('locations.id as location_id', 'locations.name as location_name', 'locations.is_active as location_is_active')
            ->addSelect('stock_levels.quantity', 'totals.type_total')
            ->where('stock_levels.quantity', '>', 0)
            ->tap(fn (Builder $query) => $this->applyCatalogFilters($query, 'stock_levels.asset_type_id', 'stock_levels.location_id'))
            ->orderBy('asset_categories.name')
            ->orderBy('asset_categories.id')
            ->orderBy('asset_types.name')
            ->orderBy('asset_types.id')
            ->orderBy('locations.name')
            ->orderBy('locations.id')
            ->get()
            ->map(function (object $row) {
                $minStock = $row->min_stock === null ? null : (int) $row->min_stock;
                $typeTotal = (int) $row->type_total;

                return [
                    'asset_type' => self::typeRef($row),
                    'location' => ['id' => $row->location_id, 'name' => $row->location_name, 'is_active' => (bool) $row->location_is_active],
                    'quantity' => (int) $row->quantity,
                    'type_total' => $typeTotal,
                    'min_stock' => $minStock,
                    'below_min' => $minStock !== null && $typeTotal < $minStock,
                ];
            })
            ->all();
    }

    private function applyCatalogFilters(Builder $query, string $typeColumn, string $locationColumn): void
    {
        $query
            ->when($this->category, fn (Builder $query) => $query->where('asset_types.asset_category_id', $this->category->id))
            ->when($this->assetType, fn (Builder $query) => $query->where($typeColumn, $this->assetType->id))
            ->when($this->location, fn (Builder $query) => $query->where($locationColumn, $this->location->id));
    }

    /**
     * @return array{id: int, name: string, category: array{id: int, name: string}}
     */
    private static function typeRef(object $row): array
    {
        return [
            'id' => $row->type_id,
            'name' => $row->type_name,
            'category' => ['id' => $row->category_id, 'name' => $row->category_name],
        ];
    }

    protected function sectionsFrom(array $data): array
    {
        $totals = $data['totals'];

        return [
            ReportSection::table(
                'Activos serializados',
                ['Categoría', 'Tipo', 'Ubicación', 'Estado', 'Cantidad'],
                array_map(fn (array $row) => [
                    $row['asset_type']['category']['name'],
                    $row['asset_type']['name'],
                    $row['location']['name'] ?? 'Sin ubicación',
                    $row['status_label'],
                    $row['count'],
                ], $data['serialized']),
            ),
            ReportSection::table(
                'Artículos por cantidad',
                ['Categoría', 'Tipo', 'Ubicación', 'Cantidad', 'Total del tipo', 'Stock mínimo', 'Bajo mínimo'],
                array_map(fn (array $row) => [
                    $row['asset_type']['category']['name'],
                    $row['asset_type']['name'],
                    $row['location']['name'].($row['location']['is_active'] ? '' : ' (inactiva)'),
                    $row['quantity'],
                    $row['type_total'],
                    $row['min_stock'],
                    self::yesNo($row['below_min']),
                ], $data['quantity']),
            ),
            ReportSection::fields('Totales', [
                'Total de activos serializados' => $totals['serialized'],
                'Disponibles' => $totals['by_status'][AssetStatus::Available->value],
                'Asignados' => $totals['by_status'][AssetStatus::Assigned->value],
                'En mantenimiento' => $totals['by_status'][AssetStatus::Maintenance->value],
                'Dados de baja' => $totals['by_status'][AssetStatus::Retired->value],
                'Unidades por cantidad' => $totals['quantity_units'],
                'Tipos bajo mínimo' => $totals['below_min_types'],
            ]),
        ];
    }
}
