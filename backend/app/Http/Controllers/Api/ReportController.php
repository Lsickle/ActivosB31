<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssetStatus;
use App\Enums\TrackingMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reportes\AssetTypeReportRequest;
use App\Http\Requests\Reportes\InventoryReportRequest;
use App\Http\Requests\Reportes\ReportRequest;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use App\Reports\AssetReport;
use App\Reports\AssetTypeReport;
use App\Reports\EmployeeReport;
use App\Reports\Export\ReportResponder;
use App\Reports\InventoryReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Reportes (008, RN-032, RN-033): todos exigen reportes.ver (FormRequest) y
 * responden JSON, Excel o PDF desde la misma clase de reporte (D-001,
 * D-002). Contrato: specs/008-modulo-reportes/contracts/api.md.
 *
 * R-1: los filtros se leen ya validados de la query (ReportRequest), nunca
 * con input()/filled(), que también mirarían el cuerpo de la petición.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportResponder $responder) {}

    /**
     * US1: inventario general.
     */
    public function inventory(InventoryReportRequest $request): JsonResponse|Response
    {
        $report = new InventoryReport(
            $request->user(),
            $this->find(AssetCategory::class, $request->filterId('asset_category_id')),
            $this->find(AssetType::class, $request->filterId('asset_type_id')),
            $this->find(Location::class, $request->filterId('location_id')),
            AssetStatus::tryFrom((string) $request->validated('status')),
        );

        return $this->responder->respond($report, $request);
    }

    /**
     * US2: por empleado (incluye inactivos). Datos personales solo con
     * empleados.ver (RN-022, D-003).
     */
    public function employee(ReportRequest $request, Employee $employee): JsonResponse|Response
    {
        return $this->responder->respond(
            new EmployeeReport($request->user(), $employee, $request->dateRange()),
            $request,
        );
    }

    /**
     * US3: por artículo, activo serializado (cualquier estado).
     */
    public function asset(ReportRequest $request, Asset $asset): JsonResponse|Response
    {
        return $this->responder->respond(
            new AssetReport($request->user(), $asset, $request->dateRange()),
            $request,
        );
    }

    /**
     * US3: por artículo, tipo por cantidad (un tipo serializado → 404).
     */
    public function assetType(AssetTypeReportRequest $request, AssetType $assetType): JsonResponse|Response
    {
        abort_unless($assetType->tracking_mode === TrackingMode::Quantity, 404);

        return $this->responder->respond(
            new AssetTypeReport(
                $request->user(),
                $assetType,
                $request->dateRange(),
                $this->find(Location::class, $request->filterId('location_id')),
            ),
            $request,
        );
    }

    /**
     * Modelo de un filtro ya validado (exists); null si el filtro no vino.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function find(string $model, ?int $id): ?Model
    {
        return $id === null ? null : $model::query()->find($id);
    }
}
