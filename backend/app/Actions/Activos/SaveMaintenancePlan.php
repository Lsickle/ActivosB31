<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\DiffsAttributes;
use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alta y edición de planes de mantenimiento (RN-029, RN-030, D-003). A-1
 * (revisión de seguridad de 005, FR-004): cada alta registra
 * "maintenance_plan_created" y cada edición con cambios efectivos (incluido
 * activar/desactivar) "maintenance_plan_updated" con {campo:{from,to}}
 * (D-002), en la misma transacción que el plan. En ambos, notes lleva el
 * nombre del plan para distinguirlo de otros planes del mismo activo.
 */
class SaveMaintenancePlan
{
    use DiffsAttributes;

    /**
     * Campos del plan que se auditan (next_due_on es derivado, D-003).
     *
     * @var list<string>
     */
    private const array AUDITED_FIELDS = ['name', 'interval_months', 'last_done_on', 'alert_days', 'is_active'];

    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Asset $asset, array $data, User $user): AssetMaintenancePlan
    {
        return DB::transaction(function () use ($asset, $data, $user) {
            $plan = new AssetMaintenancePlan($data);
            $asset->maintenancePlans()->save($plan);

            $this->recordEvent->handle($asset, AssetEventType::MaintenancePlanCreated, $user, ['notes' => $plan->name]);

            return $plan;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AssetMaintenancePlan $plan, array $data, User $user): AssetMaintenancePlan
    {
        DB::transaction(function () use ($plan, $data, $user) {
            $locked = AssetMaintenancePlan::query()->lockForUpdate()->findOrFail($plan->getKey());
            $locked->fill($data);
            $changes = $this->dirtyChanges($locked, self::AUDITED_FIELDS);

            if ($changes !== []) {
                $locked->save();
                $this->recordEvent->handle($locked->asset, AssetEventType::MaintenancePlanUpdated, $user, [
                    'notes' => $locked->name,
                    'changes' => $changes,
                ]);
            }

            $plan->setRawAttributes($locked->getAttributes(), true);
        });

        return $plan;
    }
}
