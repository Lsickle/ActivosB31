<?php

namespace App\Http\Requests\Configuracion;

use App\Models\AssetCategory;

class ListAssetCategoriesRequest extends ListCatalogRequest
{
    protected function model(): string
    {
        return AssetCategory::class;
    }
}
