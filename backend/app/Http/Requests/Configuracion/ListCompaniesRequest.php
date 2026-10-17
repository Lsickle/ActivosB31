<?php

namespace App\Http\Requests\Configuracion;

use App\Models\Company;

class ListCompaniesRequest extends ListCatalogRequest
{
    protected function model(): string
    {
        return Company::class;
    }
}
