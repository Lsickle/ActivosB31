<?php

namespace App\Http\Requests\Empleados;

use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Models\Employee;

/**
 * FR-001: listado paginado de empleados con búsqueda (D-003), filtros por
 * cargo, compañía y estado. Requiere empleados.ver (RN-022).
 */
class ListEmployeesRequest extends ListCatalogRequest
{
    protected function model(): string
    {
        return Employee::class;
    }

    protected function extraRules(): array
    {
        return [
            'position_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
        ];
    }
}
