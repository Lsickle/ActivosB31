<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\UpdateCatalogStatusRequest;
use App\Http\Requests\Empleados\ListEmployeesRequest;
use App\Http\Requests\Empleados\StoreEmployeeRequest;
use App\Http\Requests\Empleados\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Empleados de la estación (RN-021). Consultar exige empleados.ver y
 * modificar empleados.gestionar (RN-022, FR-005). No se borran, se
 * desactivan: no hay DELETE.
 */
class EmployeeController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['employees_document_type_number_unique' => 'document_number'];

    /**
     * @var list<string>
     */
    private const array RELATIONS = [
        'position:id,name,is_active',
        'company:id,name,is_active',
        'user:id,username,employee_id',
    ];

    public function index(ListEmployeesRequest $request): AnonymousResourceCollection
    {
        $employees = Employee::query()
            ->with(self::RELATIONS)
            ->search($request->validated('search'))
            ->when($request->filled('position_id'), fn ($query) => $query->where('position_id', $request->integer('position_id')))
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->alphabetical()
            ->paginate($request->perPage())
            ->withQueryString();

        return EmployeeResource::collection($employees);
    }

    /**
     * D-004 / FR-006 / US3-2: empleados activos con solo id, nombre completo
     * y cargo, para Entregas y Reportes. Sin datos personales (SC-003).
     */
    public function options(): JsonResponse
    {
        Gate::authorize('viewOptions', Employee::class);

        $employees = Employee::query()
            ->active()
            ->with('position:id,name')
            ->alphabetical()
            ->get(['id', 'first_name', 'last_name', 'position_id']);

        return response()->json([
            'data' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'position' => $employee->position->name,
            ]),
        ]);
    }

    public function show(Employee $employee): EmployeeResource
    {
        Gate::authorize('view', $employee);

        return $this->resource($employee);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        $employee = $this->translatingUniqueViolations(
            fn () => Employee::query()->create($request->validated()),
            self::UNIQUE_CONSTRAINTS,
        );

        return $this->resource($employee)->response()->setStatusCode(201);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $this->translatingUniqueViolations(fn () => $employee->update($request->validated()), self::UNIQUE_CONSTRAINTS);

        return $this->resource($employee);
    }

    /**
     * US1-4: un empleado inactivo sigue consultable y no se ofrece para
     * nuevas entregas (queda fuera de options()).
     */
    public function updateStatus(UpdateCatalogStatusRequest $request, Employee $employee): EmployeeResource
    {
        $employee->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return $this->resource($employee);
    }

    private function resource(Employee $employee): EmployeeResource
    {
        return EmployeeResource::make($employee->load(self::RELATIONS));
    }
}
