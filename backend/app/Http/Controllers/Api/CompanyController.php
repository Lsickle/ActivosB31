<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ListCompaniesRequest;
use App\Http\Requests\Configuracion\StoreCompanyRequest;
use App\Http\Requests\Configuracion\UpdateCatalogStatusRequest;
use App\Http\Requests\Configuracion\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Compañías o turnos de los empleados (RN-036, D-001 de 004): catálogo de
 * Configuración con el patrón de 002, sin precarga. No se borran, se
 * desactivan (RN-003). Autorización en cada Form Request (RN-011).
 */
class CompanyController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['companies_name_lower_unique' => 'name'];

    public function index(ListCompaniesRequest $request): AnonymousResourceCollection
    {
        $companies = Company::query()
            ->search($request->validated('search'))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return CompanyResource::collection($companies);
    }

    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        return CompanyResource::make($company);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $company = $this->translatingUniqueViolations(
            fn () => Company::query()->create($request->validated()),
            self::UNIQUE_CONSTRAINTS,
        );

        return CompanyResource::make($company)->response()->setStatusCode(201);
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $this->translatingUniqueViolations(fn () => $company->update($request->validated()), self::UNIQUE_CONSTRAINTS);

        return CompanyResource::make($company);
    }

    /**
     * US2-1: los empleados que ya tienen la compañía la conservan; solo deja
     * de ofrecerse en formularios.
     */
    public function updateStatus(UpdateCatalogStatusRequest $request, Company $company): CompanyResource
    {
        $company->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return CompanyResource::make($company);
    }
}
