<?php

namespace App\Actions\Activos;

use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use App\Models\AssetMaintenanceRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Registra un mantenimiento realizado (FR-005, US4-2). Si pertenece a un
 * plan y su fecha es posterior al último realizado (o el plan no tenía),
 * actualiza el plan y con ello el próximo vencimiento (D-003). Deja el evento
 * "maintenance_done" en el historial (D-002).
 */
class RecordMaintenance
{
    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @param  array{plan_id?: ?int, performed_on: string, description: string, performed_by?: ?string, cost?: mixed}  $data
     */
    public function handle(Asset $asset, array $data, User $user): AssetMaintenanceRecord
    {
        return DB::transaction(function () use ($asset, $data, $user) {
            $record = AssetMaintenanceRecord::query()->create([
                ...$data,
                'asset_id' => $asset->id,
                'user_id' => $user->id,
            ]);

            $planId = $data['plan_id'] ?? null;

            if ($planId !== null) {
                $plan = AssetMaintenancePlan::query()->lockForUpdate()->findOrFail($planId);

                if ($plan->last_done_on === null || $record->performed_on->greaterThan($plan->last_done_on)) {
                    $plan->update(['last_done_on' => $record->performed_on->toDateString()]);
                }
            }

            $this->recordEvent->handle($asset, AssetEventType::MaintenanceDone, $user, ['notes' => $record->description]);

            return $record;
        });
    }
}
