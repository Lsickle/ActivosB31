<?php

namespace App\Reports;

use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\Delivery;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * US3 (008): reporte por artículo de un activo serializado, en cualquier
 * estado: ficha, estado actual, quién lo tiene (RN-039, nombre y cargo, sin
 * datos personales: RN-022) e historial (asset_events de 005) del rango
 * (RN-033), más reciente primero. `detail` es el texto que arma el servidor
 * y que se imprime igual en el archivo. Sin foto (D-005).
 */
class AssetReport extends Report
{
    /**
     * Nombres legibles de los campos de `changes` (los mismos del historial
     * del activo en el frontend).
     *
     * @var array<string, string>
     */
    private const array FIELD_LABELS = [
        'asset_type_id' => 'Tipo',
        'plate' => 'Placa',
        'serial' => 'Serial',
        'brand' => 'Marca',
        'model' => 'Modelo',
        'acquisition_date' => 'Fecha de adquisición',
        'acquisition_value' => 'Valor de adquisición',
        'useful_life_months' => 'Vida útil (meses)',
        'expires_on' => 'Vencimiento',
        'notes' => 'Observaciones',
        'photo' => 'Foto',
        'name' => 'Nombre',
        'interval_months' => 'Periodicidad (meses)',
        'last_done_on' => 'Último realizado',
        'alert_days' => 'Anticipación (días)',
        'is_active' => 'Activo',
    ];

    public function __construct(
        User $user,
        private readonly Asset $asset,
        private readonly DateRange $range,
    ) {
        parent::__construct($user);
    }

    public function title(): string
    {
        return 'Reporte por activo';
    }

    public function fileBaseName(): string
    {
        return 'reporte-activo';
    }

    protected function filters(): array
    {
        return $this->range->filters();
    }

    protected function body(): array
    {
        $asset = Asset::query()
            ->whereKey($this->asset->id)
            ->withNextMaintenance()
            ->with([
                'assetType:id,name,asset_category_id',
                'assetType.category:id,name',
                'location:id,name',
                'holder:id,first_name,last_name,position_id',
                'holder.position:id,name',
            ])
            ->firstOrFail();

        $events = $asset->events()
            ->with(['fromLocation:id,name', 'toLocation:id,name', 'employee:id,first_name,last_name', 'user:id,name'])
            ->tap(fn ($query) => $this->range->apply($query, 'created_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $deliveryCodes = Delivery::query()
            ->whereIn('id', $events->pluck('delivery_id')->filter()->unique())
            ->pluck('code', 'id');

        return [
            'asset' => [
                'id' => $asset->id,
                'plate' => $asset->plate,
                'serial' => $asset->serial,
                'brand' => $asset->brand,
                'model' => $asset->model,
                'status' => $asset->status->value,
                'status_label' => $asset->status->label(),
                'asset_type' => [
                    'id' => $asset->assetType->id,
                    'name' => $asset->assetType->name,
                    'category' => $asset->assetType->category->only(['id', 'name']),
                ],
                'location' => $asset->location?->only(['id', 'name']),
                'holder' => $asset->holder === null ? null : [
                    'id' => $asset->holder->id,
                    'full_name' => $asset->holder->full_name,
                    'position' => $asset->holder->position?->name,
                ],
                'acquisition_date' => $asset->acquisition_date?->toDateString(),
                'expires_on' => $asset->expires_on?->toDateString(),
                'next_maintenance_on' => $asset->next_maintenance_on === null ? null : substr((string) $asset->next_maintenance_on, 0, 10),
            ],
            'events' => $events->map(fn (AssetEvent $event) => [
                'id' => $event->id,
                'event' => $event->event->value,
                'event_label' => $event->event->label(),
                'detail' => self::detail($event, $deliveryCodes),
                'user' => $event->user?->only(['id', 'name']),
                'created_at' => $event->created_at,
            ])->values()->all(),
            'totals' => ['events' => $events->count()],
        ];
    }

    /**
     * Texto del evento (D-001: el mismo en pantalla y en el archivo).
     *
     * @param  Collection<int, string>  $deliveryCodes
     */
    private static function detail(AssetEvent $event, Collection $deliveryCodes): ?string
    {
        $employee = $event->employee?->full_name;
        $delivery = $event->delivery_id !== null ? 'Entrega '.($deliveryCodes[$event->delivery_id] ?? $event->delivery_id) : null;
        $statusChange = $event->from_status !== null && $event->to_status !== null
            ? "{$event->from_status->label()} → {$event->to_status->label()}"
            : null;

        $parts = match ($event->event) {
            AssetEventType::Created => [$event->toLocation ? "Ubicación: {$event->toLocation->name}" : null],
            AssetEventType::Updated => [self::changes($event->changes) ?? $event->notes],
            AssetEventType::StatusChanged => [$statusChange, $event->notes ? "Motivo: {$event->notes}" : null],
            AssetEventType::LocationChanged => [($event->fromLocation?->name ?? 'Sin ubicación').' → '.($event->toLocation?->name ?? 'Sin ubicación')],
            AssetEventType::Delivered => [$employee ? "Entregado a {$employee}" : null, $delivery, $event->notes],
            AssetEventType::Returned => [
                $employee ? "Devuelto por {$employee}" : null,
                $delivery,
                $statusChange,
                $event->toLocation ? "Ubicación: {$event->toLocation->name}" : null,
                $event->notes,
            ],
            AssetEventType::MaintenanceDone => [$event->notes],
            AssetEventType::Retired => [$event->notes ? "Motivo: {$event->notes}" : null],
            AssetEventType::MaintenancePlanCreated => [$event->notes ? "Plan: {$event->notes}" : null],
            AssetEventType::MaintenancePlanUpdated => [$event->notes ? "Plan: {$event->notes}" : null, self::changes($event->changes)],
        };

        $parts = array_values(array_filter($parts, fn (?string $part) => $part !== null && $part !== ''));

        return $parts === [] ? null : implode('. ', $parts);
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>|null  $changes
     */
    private static function changes(?array $changes): ?string
    {
        if ($changes === null || $changes === []) {
            return null;
        }

        $show = fn (mixed $value) => match (true) {
            $value === null, $value === '' => '(vacío)',
            is_bool($value) => self::yesNo($value),
            default => (string) $value,
        };

        return collect($changes)
            ->map(fn (array $change, string $field) => (self::FIELD_LABELS[$field] ?? $field).': '.$show($change['from'] ?? null).' → '.$show($change['to'] ?? null))
            ->implode('; ');
    }

    protected function sectionsFrom(array $data): array
    {
        $asset = $data['asset'];

        return [
            ReportSection::fields('Activo', [
                'Placa' => $asset['plate'],
                'Serial' => $asset['serial'],
                'Marca' => $asset['brand'],
                'Modelo' => $asset['model'],
                'Estado' => $asset['status_label'],
                'Categoría' => $asset['asset_type']['category']['name'],
                'Tipo' => $asset['asset_type']['name'],
                'Ubicación' => $asset['location']['name'] ?? null,
                'Poseedor' => match (true) {
                    $asset['holder'] === null => null,
                    $asset['holder']['position'] === null => $asset['holder']['full_name'],
                    default => "{$asset['holder']['full_name']} ({$asset['holder']['position']})",
                },
                'Fecha de adquisición' => $asset['acquisition_date'],
                'Vencimiento' => $asset['expires_on'],
                'Próximo mantenimiento' => $asset['next_maintenance_on'],
            ]),
            ReportSection::table(
                'Historial',
                ['Fecha', 'Evento', 'Detalle', 'Usuario'],
                array_map(fn (array $event) => [
                    self::localDateTime($event['created_at']),
                    $event['event_label'],
                    $event['detail'],
                    $event['user']['name'] ?? null,
                ], $data['events']),
            ),
            ReportSection::fields('Totales', ['Eventos' => $data['totals']['events']]),
        ];
    }
}
