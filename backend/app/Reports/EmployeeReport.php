<?php

namespace App\Reports;

use App\Enums\DeliveryStatus;
use App\Enums\Permission;
use App\Models\DeliveryItem;
use App\Models\DeliveryReturn;
use App\Models\Employee;
use App\Models\User;
use App\Queries\EmployeeHoldings;

/**
 * US2 (008): lo que el empleado tiene hoy (no depende del rango) y el
 * historial de entregas y devoluciones del rango (RN-033), más recientes
 * primero. Incluye empleados inactivos.
 *
 * RN-022 / D-003: documento, teléfono, correo y compañía solo si el usuario
 * tiene empleados.ver; si no, las claves no existen, y por lo tanto tampoco
 * llegan al Excel ni al PDF (salen del mismo arreglo).
 */
class EmployeeReport extends Report
{
    private const string EVENT_DELIVERY = 'delivery';

    private const string EVENT_RETURN = 'return';

    public function __construct(
        User $user,
        private readonly Employee $employee,
        private readonly DateRange $range,
    ) {
        parent::__construct($user);
    }

    public function title(): string
    {
        return 'Reporte por empleado';
    }

    public function fileBaseName(): string
    {
        return 'reporte-empleado';
    }

    protected function filters(): array
    {
        return $this->range->filters();
    }

    private function showsPersonalData(): bool
    {
        return $this->user->hasPermission(Permission::EmpleadosVer);
    }

    protected function body(): array
    {
        $holdings = $this->holdings();
        $history = $this->history();

        return [
            'employee' => $this->employeeData(),
            'holdings' => $holdings,
            'history' => $history,
            'totals' => [
                'pending_units' => collect($holdings)->sum('pending_quantity'),
                'delivered_units' => collect($history)->where('event', self::EVENT_DELIVERY)->sum('quantity'),
                'returned_units' => collect($history)->where('event', self::EVENT_RETURN)->sum('quantity'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeData(): array
    {
        $employee = $this->employee->loadMissing(['position:id,name', 'company:id,name']);

        $data = [
            'id' => $employee->id,
            'full_name' => $employee->full_name,
            'position' => $employee->position?->name,
            'is_active' => $employee->is_active,
        ];

        // RN-022: datos personales solo con empleados.ver.
        if ($this->showsPersonalData()) {
            $data += [
                'document_type' => $employee->document_type,
                'document_number' => $employee->document_number,
                'phone' => $employee->phone,
                'email' => $employee->email,
                'company' => $employee->company?->name,
            ];
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function holdings(): array
    {
        return app(EmployeeHoldings::class)->get($this->employee)
            ->map(fn (DeliveryItem $item) => [
                'delivery' => [
                    'id' => $item->delivery->id,
                    'code' => $item->delivery->code,
                    'kind' => $item->delivery->kind->value,
                    'kind_label' => $item->delivery->kind->label(),
                    'due_on' => $item->delivery->due_on?->toDateString(),
                    'status' => $item->delivery->status()->value,
                ],
                'asset' => $item->asset?->only(['id', 'plate']),
                'asset_type' => [
                    'id' => $item->assetType->id,
                    'name' => $item->assetType->name,
                    'tracking_mode' => $item->assetType->tracking_mode->value,
                ],
                'pending_quantity' => $item->pending_quantity,
                'delivered_at' => $item->delivery->delivered_at,
            ])
            ->values()
            ->all();
    }

    /**
     * Una fila por ítem entregado y por devolución dentro del rango.
     *
     * @return list<array<string, mixed>>
     */
    private function history(): array
    {
        $items = DeliveryItem::query()
            ->select('delivery_items.*')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_items.delivery_id')
            ->where('deliveries.employee_id', $this->employee->id)
            ->tap(fn ($query) => $this->range->apply($query, 'deliveries.delivered_at'))
            ->with(['delivery:id,code,kind,delivered_at', 'asset:id,plate', 'assetType:id,name'])
            ->get();

        $returns = DeliveryReturn::query()
            ->select('delivery_returns.*')
            ->join('delivery_items', 'delivery_items.id', '=', 'delivery_returns.delivery_item_id')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_items.delivery_id')
            ->where('deliveries.employee_id', $this->employee->id)
            ->tap(fn ($query) => $this->range->apply($query, 'delivery_returns.returned_at'))
            ->with(['item:id,delivery_id,asset_id,asset_type_id', 'item.delivery:id,code,kind', 'item.asset:id,plate', 'item.assetType:id,name'])
            ->get();

        $rows = $items->map(fn (DeliveryItem $item) => [
            'sort' => [$item->delivery->delivered_at->getTimestamp(), $item->delivery->delivered_at->micro, 0, $item->id],
            'row' => self::historyRow(self::EVENT_DELIVERY, $item->delivery->delivered_at, $item, $item->quantity, null),
        ])->concat($returns->map(fn (DeliveryReturn $return) => [
            'sort' => [$return->returned_at->getTimestamp(), $return->returned_at->micro, 1, $return->id],
            'row' => self::historyRow(self::EVENT_RETURN, $return->returned_at, $return->item, $return->quantity, $return),
        ]));

        // Más recientes primero; a igual hora, la devolución antes que la entrega.
        return $rows->sortByDesc('sort')->pluck('row')->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function historyRow(string $event, mixed $date, DeliveryItem $item, int $quantity, ?DeliveryReturn $return): array
    {
        return [
            'date' => $date,
            'event' => $event,
            'event_label' => $event === self::EVENT_DELIVERY ? 'Entrega' : 'Devolución',
            'delivery' => [
                'id' => $item->delivery->id,
                'code' => $item->delivery->code,
                'kind' => $item->delivery->kind->value,
                'kind_label' => $item->delivery->kind->label(),
            ],
            'asset' => $item->asset?->only(['id', 'plate']),
            'asset_type' => $item->assetType->only(['id', 'name']),
            'quantity' => $quantity,
            'condition' => $return?->condition->value,
            'condition_label' => $return?->condition->label(),
        ];
    }

    protected function sectionsFrom(array $data): array
    {
        $employee = $data['employee'];
        $fields = [
            'Nombre' => $employee['full_name'],
            'Cargo' => $employee['position'],
            'Estado' => $employee['is_active'] ? 'Activo' : 'Inactivo',
        ];

        // RN-022: solo si el arreglo los trae (empleados.ver).
        $personal = [
            'document_type' => 'Tipo de documento',
            'document_number' => 'Número de documento',
            'phone' => 'Teléfono',
            'email' => 'Correo',
            'company' => 'Compañía',
        ];
        foreach ($personal as $key => $label) {
            if (array_key_exists($key, $employee)) {
                $fields[$label] = $employee[$key];
            }
        }

        return [
            ReportSection::fields('Empleado', $fields),
            ReportSection::table(
                'Tiene actualmente',
                ['Entrega', 'Tipo', 'Fecha de entrega', 'Devolución esperada', 'Estado', 'Placa', 'Artículo', 'Pendiente'],
                array_map(fn (array $row) => [
                    $row['delivery']['code'],
                    $row['delivery']['kind_label'],
                    self::localDateTime($row['delivered_at']),
                    $row['delivery']['due_on'],
                    DeliveryStatus::from($row['delivery']['status'])->label(),
                    $row['asset']['plate'] ?? null,
                    $row['asset_type']['name'],
                    $row['pending_quantity'],
                ], $data['holdings']),
            ),
            ReportSection::table(
                'Historial de entregas y devoluciones',
                ['Fecha', 'Evento', 'Entrega', 'Tipo', 'Placa', 'Artículo', 'Cantidad', 'Estado devuelto'],
                array_map(fn (array $row) => [
                    self::localDateTime($row['date']),
                    $row['event_label'],
                    $row['delivery']['code'],
                    $row['delivery']['kind_label'],
                    $row['asset']['plate'] ?? null,
                    $row['asset_type']['name'],
                    $row['quantity'],
                    $row['condition_label'],
                ], $data['history']),
            ),
            ReportSection::fields('Totales', [
                'Unidades pendientes' => $data['totals']['pending_units'],
                'Unidades entregadas en el rango' => $data['totals']['delivered_units'],
                'Unidades devueltas en el rango' => $data['totals']['returned_units'],
            ]),
        ];
    }
}
