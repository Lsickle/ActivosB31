<?php

namespace App\Http\Requests\Configuracion;

use App\Models\Location;

/**
 * FR-004: además filtra por tipo de ubicación (D-004).
 */
class ListLocationsRequest extends ListCatalogRequest
{
    protected function model(): string
    {
        return Location::class;
    }

    protected function extraRules(): array
    {
        return [
            'location_type_id' => ['nullable', 'integer'],
        ];
    }
}
