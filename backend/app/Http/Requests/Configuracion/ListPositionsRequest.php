<?php

namespace App\Http\Requests\Configuracion;

use App\Models\Position;

class ListPositionsRequest extends ListCatalogRequest
{
    protected function model(): string
    {
        return Position::class;
    }
}
