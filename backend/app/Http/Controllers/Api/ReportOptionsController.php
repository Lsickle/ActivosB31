<?php

namespace App\Http\Controllers\Api;

use App\Enums\TrackingMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\ReportOptionsRequest;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Buscadores de Reportes (008): con reportes.ver y sin exigir el permiso del
 * módulo de origen. Incluyen inactivos y dados de baja (el reporte sirve
 * para el historial). Hasta 20 resultados; sin `search`, lista vacía.
 */
class ReportOptionsController extends Controller
{
    /**
     * Activos por placa o serial (sin distinguir mayúsculas), todos los
     * estados, ordenados por placa.
     */
    public function assets(ReportOptionsRequest $request): JsonResponse
    {
        $term = $request->term();

        if ($term === null) {
            return response()->json(['data' => []]);
        }

        $pattern = '%'.addcslashes(mb_strtolower($term), '\\%_').'%';

        $assets = Asset::query()
            ->where(fn (Builder $query) => $query
                ->whereRaw('lower(plate) like ?', [$pattern])
                ->orWhereRaw('lower(serial) like ?', [$pattern]))
            ->with('assetType:id,name')
            ->orderBy('plate')
            ->orderBy('id')
            ->limit(ReportOptionsRequest::LIMIT)
            ->get(['id', 'plate', 'status', 'asset_type_id']);

        return response()->json(['data' => $assets->map(fn (Asset $asset) => [
            'id' => $asset->id,
            'plate' => $asset->plate,
            'status' => $asset->status->value,
            'status_label' => $asset->status->label(),
            'asset_type' => $asset->assetType->only(['id', 'name']),
        ])->values()]);
    }

    /**
     * Empleados por nombre (incluidos inactivos), solo nombre y cargo.
     * RN-022: no busca por documento, para no permitir sondearlo sin
     * empleados.ver.
     */
    public function employees(ReportOptionsRequest $request): JsonResponse
    {
        $term = $request->term();

        if ($term === null) {
            return response()->json(['data' => []]);
        }

        $fold = fn (string $expression) => "translate(lower({$expression}), 'áéíóúüñ', 'aeiouun')";
        $pattern = '%'.addcslashes((string) preg_replace('/\s+/u', ' ', $term), '\\%_').'%';

        $employees = Employee::query()
            ->where(function (Builder $query) use ($fold, $pattern) {
                foreach (['first_name', 'last_name', "first_name || ' ' || last_name", "last_name || ' ' || first_name"] as $expression) {
                    $query->orWhereRaw("{$fold($expression)} like {$fold('?')}", [$pattern]);
                }
            })
            ->with('position:id,name')
            ->alphabetical()
            ->limit(ReportOptionsRequest::LIMIT)
            ->get(['id', 'first_name', 'last_name', 'position_id', 'is_active']);

        return response()->json(['data' => $employees->map(fn (Employee $employee) => [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'position' => $employee->position?->name,
            'is_active' => $employee->is_active,
        ])->values()]);
    }

    /**
     * Tipos por cantidad por nombre (sin tildes ni mayúsculas), incluidos
     * inactivos.
     */
    public function assetTypes(ReportOptionsRequest $request): JsonResponse
    {
        $term = $request->term();

        if ($term === null) {
            return response()->json(['data' => []]);
        }

        $types = AssetType::query()
            ->where('tracking_mode', TrackingMode::Quantity->value)
            ->search($term)
            ->with('category:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->limit(ReportOptionsRequest::LIMIT)
            ->get(['id', 'name', 'is_active', 'asset_category_id']);

        return response()->json(['data' => $types->map(fn (AssetType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'is_active' => $type->is_active,
            'category' => $type->category->only(['id', 'name']),
        ])->values()]);
    }
}
