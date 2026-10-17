<?php

namespace App\Http\Requests\Configuracion;

use App\Models\LocationType;

class ListLocationTypesRequest extends ListCatalogRequest
{
    protected function model(): string
    {
        return LocationType::class;
    }
}
