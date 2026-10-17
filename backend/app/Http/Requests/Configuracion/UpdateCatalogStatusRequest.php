<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Activar o desactivar un registro de cualquier catálogo de Configuración
 * (RN-003, D-006). Autoriza contra la Policy del modelo de la ruta.
 */
class UpdateCatalogStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->record());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function record(): Model
    {
        $record = collect($this->route()?->parameters() ?? [])->first(fn (mixed $parameter) => $parameter instanceof Model);

        abort_unless($record instanceof Model, 404);

        return $record;
    }
}
