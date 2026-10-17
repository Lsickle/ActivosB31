<?php

namespace App\Http\Requests\Entregas;

use App\Actions\Entregas\CreateDelivery;
use App\Actions\Entregas\ProcessDeliverySignature;
use App\Enums\DeliveryKind;
use App\Enums\TrackingMode;
use App\Models\Delivery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * POST /api/deliveries (FR-001, D-002, D-003; contrato
 * specs/007-modulo-entregas/contracts/api.md). Requiere entregas.gestionar.
 *
 * - RN-023: en préstamo, due_on obligatoria y ≥ hoy (America/Bogota); en
 *   dotación se ignora (se guarda null).
 * - RN-024 / D-002: la firma se valida y re-codifica aquí
 *   (ProcessDeliverySignature); el PNG resultante queda en signaturePng().
 * - RN-025: uno o más artículos; cada uno es {asset_id} o {asset_type_id,
 *   quantity}, sin repetir.
 *
 * Empleado activo, activos disponibles, tipos por cantidad y existencia
 * suficiente los valida App\Actions\Entregas\CreateDelivery (items.N.*).
 */
class StoreDeliveryRequest extends FormRequest
{
    public const int NOTES_MAX = 1000;

    public const int MAX_ITEMS = 100;

    public const int MAX_QUANTITY = 2_147_483_647;

    private ?string $signaturePng = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', Delivery::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')],
            'kind' => ['required', Rule::enum(DeliveryKind::class)],
            'due_on' => [
                'exclude_unless:kind,'.DeliveryKind::Prestamo->value,
                'required', 'date_format:Y-m-d', 'after_or_equal:'.Delivery::today(),
            ],
            'from_location_id' => ['required', 'integer', Rule::exists('locations', 'id')],
            'notes' => ['nullable', 'string', 'max:'.self::NOTES_MAX],
            'signature' => ['required', 'string', 'max:'.ProcessDeliverySignature::MAX_DATA_URL_LENGTH],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.asset_id' => [
                'nullable', 'required_without:items.*.asset_type_id', 'prohibits:items.*.asset_type_id,items.*.quantity',
                'integer', 'distinct', Rule::exists('assets', 'id'),
            ],
            'items.*.asset_type_id' => [
                'nullable', 'required_without:items.*.asset_id', 'integer', 'distinct',
                // RN-007: por cantidad solo se entregan tipos controlados por cantidad.
                Rule::exists('asset_types', 'id')->where('tracking_mode', TrackingMode::Quantity->value),
            ],
            'items.*.quantity' => ['nullable', 'required_with:items.*.asset_type_id', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_on.required' => 'Indica la fecha de devolución esperada.',
            'due_on.after_or_equal' => 'La fecha de devolución no puede ser anterior a hoy.',
            'signature.required' => 'La firma del empleado es obligatoria.',
            'signature.max' => ProcessDeliverySignature::TOO_LARGE_MESSAGE,
            'items.required' => 'Agrega al menos un artículo.',
            'items.min' => 'Agrega al menos un artículo.',
            'items.*.asset_id.required_without' => 'Indica el activo o el tipo y la cantidad.',
            'items.*.asset_id.prohibits' => 'Un artículo es un activo o un tipo con cantidad, no ambos.',
            'items.*.asset_id.distinct' => 'El activo está repetido.',
            'items.*.asset_type_id.distinct' => 'El tipo de activo está repetido.',
            'items.*.asset_type_id.exists' => CreateDelivery::NOT_QUANTITY_TYPE_MESSAGE,
            'items.*.quantity.min' => 'La cantidad debe ser un número entero mayor que 0.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero mayor que 0.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'empleado',
            'kind' => 'tipo de entrega',
            'due_on' => 'fecha de devolución esperada',
            'from_location_id' => 'ubicación de origen',
            'notes' => 'observaciones',
            'signature' => 'firma',
            'items' => 'artículos',
            'items.*.asset_id' => 'activo',
            'items.*.asset_type_id' => 'tipo de activo',
            'items.*.quantity' => 'cantidad',
        ];
    }

    /**
     * D-002: valida y re-codifica la firma (solo si pasó las reglas básicas).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('signature')) {
                return;
            }

            try {
                $this->signaturePng = app(ProcessDeliverySignature::class)->handle((string) $this->input('signature'));
            } catch (ValidationException $exception) {
                $validator->errors()->add('signature', $exception->errors()['signature'][0]);
            }
        }];
    }

    /**
     * PNG re-codificado de la firma (después de validar).
     */
    public function signaturePng(): string
    {
        return (string) $this->signaturePng;
    }

    /**
     * Artículos validados con el índice del payload (las claves de error
     * items.N.* usan ese índice). validated() no garantiza el orden de las
     * claves: se ordenan sin renumerar.
     *
     * @return array<int, array{asset_id?: int|null, asset_type_id?: int|null, quantity?: int|null}>
     */
    public function items(): array
    {
        $items = $this->validated('items');
        ksort($items);

        return $items;
    }

    public function kind(): DeliveryKind
    {
        return DeliveryKind::from($this->validated('kind'));
    }
}
