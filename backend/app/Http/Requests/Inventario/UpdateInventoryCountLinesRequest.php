<?php

namespace App\Http\Requests\Inventario;

use App\Enums\TrackingMode;
use App\Exceptions\InventoryCountClosedException;
use App\Http\Requests\Inventario\StoreInventoryMovementRequest as MovementRules;
use App\Models\AssetType;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/inventory/counts/{id}/lines (US3, D-004). Cada elemento es:
 * - renglón de cantidad existente: {id, counted_quantity|null};
 * - renglón de serializado existente: {id, asset_id, found: bool};
 * - tipo nuevo sin existencia: {asset_type_id, counted_quantity} (se crea con
 *   expected_quantity = 0; solo tipos por cantidad disponibles).
 * Los renglones no enviados no cambian. Errores en lines.N.campo (N = orden
 * de envío). Requiere inventario.gestionar; un conteo cerrado responde 409.
 */
class UpdateInventoryCountLinesRequest extends FormRequest
{
    public const int MAX_LINES = 1000;

    public function authorize(): bool
    {
        $count = $this->inventoryCount();

        if (! $this->user()->can('update', $count)) {
            return false;
        }

        if (! $count->isOpen()) {
            throw new InventoryCountClosedException;
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.id' => ['nullable', 'integer'],
            'lines.*.asset_type_id' => ['nullable', 'required_without:lines.*.id', 'integer'],
            'lines.*.asset_id' => ['nullable', 'integer'],
            'lines.*.counted_quantity' => ['nullable', 'integer', 'min:0', 'max:'.MovementRules::MAX_QUANTITY],
            'lines.*.found' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'lines' => 'renglones',
            'lines.*.id' => 'renglón',
            'lines.*.asset_type_id' => 'tipo de activo',
            'lines.*.asset_id' => 'activo',
            'lines.*.counted_quantity' => 'cantidad contada',
            'lines.*.found' => 'encontrado',
        ];
    }

    /**
     * Reglas que dependen del conteo: el renglón pertenece a él, coincide su
     * clase (cantidad o serializado) y los tipos nuevos son por cantidad,
     * disponibles y no repetidos.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $input = $this->input('lines');

            if (! is_array($input)) {
                return;
            }

            $count = $this->inventoryCount();
            /** @var Collection<int, InventoryCountLine> $existing */
            $existing = $count->lines()->get()->keyBy('id');
            $typesInCount = $existing->whereNull('asset_id')->pluck('asset_type_id')->all();
            $newTypeIds = collect($input)->filter(fn ($line) => is_array($line) && ! isset($line['id']))->pluck('asset_type_id')->filter(fn ($id) => is_numeric($id));
            $allowedTypeIds = AssetType::query()->available()
                ->where('tracking_mode', TrackingMode::Quantity->value)
                ->whereKey($newTypeIds->map(fn ($id) => (int) $id)->all())
                ->pluck('id')
                ->all();

            foreach ($input as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $fail = fn (string $field, string $message) => $validator->errors()->add("lines.{$index}.{$field}", $message);

                if (isset($line['id'])) {
                    if (! is_numeric($line['id'])) {
                        continue;
                    }

                    $current = $existing->get((int) $line['id']);

                    if ($current === null) {
                        $fail('id', 'El renglón no pertenece al conteo.');
                    } elseif ($current->isSerialized()) {
                        if (($line['found'] ?? null) === null) {
                            $fail('found', 'Indica si el activo se encontró.');
                        }
                        if (isset($line['asset_id']) && (int) $line['asset_id'] !== $current->asset_id) {
                            $fail('asset_id', 'El activo no corresponde al renglón.');
                        }
                        if (isset($line['counted_quantity'])) {
                            $fail('counted_quantity', 'Los activos serializados se marcan como encontrados, sin cantidad.');
                        }
                    } else {
                        if (isset($line['found'])) {
                            $fail('found', 'Los renglones de cantidad registran la cantidad contada.');
                        }
                        if (isset($line['asset_id'])) {
                            $fail('asset_id', 'El renglón no es de un activo serializado.');
                        }
                    }

                    continue;
                }

                if (isset($line['asset_id']) || isset($line['found'])) {
                    $fail(isset($line['asset_id']) ? 'asset_id' : 'found', 'Los activos serializados se registran sobre su renglón.');
                }

                if (! isset($line['asset_type_id']) || ! is_numeric($line['asset_type_id'])) {
                    continue;
                }

                $typeId = (int) $line['asset_type_id'];

                if (! in_array($typeId, $allowedTypeIds, true)) {
                    $fail('asset_type_id', 'Elige un tipo de artículo por cantidad activo.');
                } elseif (in_array($typeId, $typesInCount, true)) {
                    $fail('asset_type_id', 'El tipo ya está en el conteo.');
                } else {
                    $typesInCount[] = $typeId;
                }
            }
        }];
    }

    public function inventoryCount(): InventoryCount
    {
        return $this->route('count');
    }
}
