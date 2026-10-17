<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordSecurityEvent;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\UpdateSettingsRequest;
use App\Models\AppSetting;
use App\Models\AssetCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Ajustes generales (D-005 de 005). Por ahora la anticipación global de las
 * alertas de vencimiento (RN-030).
 */
class SettingsController extends Controller
{
    /**
     * Lo lee cualquier usuario con al menos un permiso de ver, igual que
     * /api/configuracion/options (D-005 de 002): no contiene datos sensibles
     * y lo necesitan las pantallas de alertas.
     */
    public function show(): JsonResponse
    {
        Gate::authorize('viewOptions', AssetCategory::class);

        return self::response();
    }

    /**
     * B-5 (revisión de seguridad de 005): un cambio efectivo queda en la
     * bitácora de seguridad (003) con el valor anterior y el nuevo, en la
     * misma transacción (FR-009 de 003).
     */
    public function update(UpdateSettingsRequest $request, RecordSecurityEvent $recordEvent): JsonResponse
    {
        DB::transaction(function () use ($request, $recordEvent) {
            $before = AppSetting::expiryAlertDays();
            $after = $request->integer(AppSetting::EXPIRY_ALERT_DAYS);

            if ($before === $after) {
                return;
            }

            AppSetting::put(AppSetting::EXPIRY_ALERT_DAYS, $after);

            $recordEvent->handle(SecurityEventType::SettingsUpdated, $request->user(), metadata: [
                'changes' => [AppSetting::EXPIRY_ALERT_DAYS => [$before, $after]],
            ]);
        });

        return self::response();
    }

    private static function response(): JsonResponse
    {
        return response()->json(['data' => [
            AppSetting::EXPIRY_ALERT_DAYS => AppSetting::expiryAlertDays(),
        ]]);
    }
}
