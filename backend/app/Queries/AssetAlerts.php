<?php

namespace App\Queries;

use App\Enums\AssetStatus;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Alertas de mantenimientos y vencimientos (005: US4-4, US5, RN-030, RN-031,
 * D-004). "Hoy" es la fecha en America/Bogota. Nunca incluye activos dados
 * de baja (US5-2). Vence hoy = próximo, no vencido.
 *
 * - maintenance: planes activos con next_due_on <= hoy + alert_days del plan.
 * - expirations: activos con expires_on <= hoy + expiry_alert_days (global).
 *
 * La usan GET /api/assets/alerts (005) y el tablero de Inicio (008, D-004):
 * misma consulta, misma forma.
 */
class AssetAlerts
{
    private const string ASSET_COLUMNS = 'id,plate,asset_type_id';

    private const string TYPE_COLUMNS = 'assetType:id,name';

    /**
     * @return array{maintenance: list<array<string, mixed>>, expirations: list<array<string, mixed>>}
     */
    public function get(): array
    {
        $today = Carbon::now(Asset::LOCAL_TIMEZONE)->toDateString();
        $notRetired = fn (Builder $asset) => $asset->where('status', '!=', AssetStatus::Retired->value);

        $plans = AssetMaintenancePlan::query()
            ->where('is_active', true)
            ->whereHas('asset', $notRetired)
            ->whereRaw('next_due_on <= ?::date + alert_days', [$today])
            ->with(['asset:'.self::ASSET_COLUMNS, 'asset.'.self::TYPE_COLUMNS])
            ->orderBy('next_due_on')
            ->orderBy('id')
            ->get();

        $expiring = Asset::query()
            ->tap($notRetired)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<=', Carbon::parse($today)->addDays(AppSetting::expiryAlertDays())->toDateString())
            ->with(self::TYPE_COLUMNS)
            ->orderBy('expires_on')
            ->orderBy('id')
            ->get(explode(',', self::ASSET_COLUMNS.',expires_on'));

        return [
            'maintenance' => $plans->map(fn (AssetMaintenancePlan $plan) => [
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'next_due_on' => $plan->next_due_on->toDateString(),
                'overdue' => $plan->next_due_on->toDateString() < $today,
                'asset' => self::assetSummary($plan->asset),
            ])->values()->all(),
            'expirations' => $expiring->map(fn (Asset $asset) => [
                'expires_on' => $asset->expires_on->toDateString(),
                'overdue' => $asset->expires_on->toDateString() < $today,
                'asset' => self::assetSummary($asset),
            ])->values()->all(),
        ];
    }

    /**
     * @return array{id: int, plate: string, asset_type: array{id: int, name: string}}
     */
    private static function assetSummary(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'plate' => $asset->plate,
            'asset_type' => ['id' => $asset->assetType->id, 'name' => $asset->assetType->name],
        ];
    }
}
