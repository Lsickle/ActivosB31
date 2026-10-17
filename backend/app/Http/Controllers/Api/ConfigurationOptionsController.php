<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Company;
use App\Models\Location;
use App\Models\LocationType;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Opciones disponibles de Configuración para formularios y otros módulos
 * (FR-010, D-005): solo registros activos y, en tipos de activo y
 * ubicaciones, solo los de un padre activo (FR-008, SC-005). Sin paginar.
 *
 * D-005 (resuelto 2026-10-17): lo lee cualquier usuario activo con al menos
 * un permiso de ver de cualquier módulo (RN-011); son nombres de catálogo.
 */
class ConfigurationOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewOptions', AssetCategory::class);

        return response()->json(['data' => [
            'asset_categories' => AssetCategory::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'asset_types' => AssetType::query()
                ->available()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'asset_category_id', 'tracking_mode']),
            'location_types' => LocationType::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'locations' => Location::query()
                ->available()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'location_type_id']),
            // 004 (D-001): cargos y compañías activos para el formulario de empleado.
            'positions' => Position::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'companies' => Company::query()->active()->orderBy('name')->orderBy('id')->get(['id', 'name']),
        ]]);
    }
}
