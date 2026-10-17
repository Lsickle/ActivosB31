<?php

use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Enums\TrackingMode;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetMaintenanceRecord;
use App\Models\AssetType;
use App\Models\User;

describe('AssetStatus (RN-005, D-001)', function () {
    it('tiene las etiquetas en español', function () {
        expect(AssetStatus::Available->label())->toBe('Disponible')
            ->and(AssetStatus::Assigned->label())->toBe('Asignado')
            ->and(AssetStatus::Maintenance->label())->toBe('En mantenimiento')
            ->and(AssetStatus::Retired->label())->toBe('Dado de baja');
    });

    it('D-001: transiciones manuales Disponible ⇄ En mantenimiento y → Dado de baja', function (AssetStatus $from, AssetStatus $to, bool $allowed) {
        expect($from->canTransitionManuallyTo($to))->toBe($allowed);
    })->with([
        [AssetStatus::Available, AssetStatus::Maintenance, true],
        [AssetStatus::Maintenance, AssetStatus::Available, true],
        [AssetStatus::Available, AssetStatus::Retired, true],
        [AssetStatus::Maintenance, AssetStatus::Retired, true],
        [AssetStatus::Available, AssetStatus::Assigned, false],
        [AssetStatus::Assigned, AssetStatus::Available, false],
        [AssetStatus::Assigned, AssetStatus::Retired, false],
        [AssetStatus::Retired, AssetStatus::Available, false],
        [AssetStatus::Retired, AssetStatus::Maintenance, false],
        [AssetStatus::Available, AssetStatus::Available, false],
    ]);

    it('D-002: los tipos de evento del historial tienen etiqueta', function () {
        expect(AssetEventType::Created->label())->toBe('Registro')
            ->and(AssetEventType::Delivered->label())->toBe('Entrega')
            ->and(AssetEventType::Retired->label())->toBe('Baja');
    });
});

describe('Asset', function () {
    it('D-006: guarda la placa recortada y en mayúsculas', function () {
        $asset = Asset::factory()->create(['plate' => '  b31-0001 ']);

        expect($asset->fresh()->plate)->toBe('B31-0001')
            ->and($asset->fresh()->status)->toBe(AssetStatus::Available);
    });

    it('FR-009 de 002: AssetType::hasAssets() refleja si el tipo tiene activos', function () {
        $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized]);

        expect($type->hasAssets())->toBeFalse();

        Asset::factory()->for($type, 'assetType')->create();

        expect($type->hasAssets())->toBeTrue();
    });
});

describe('solo inserción a nivel de modelo (FR-004, SC-003)', function () {
    it('AssetEvent no se modifica ni se borra', function () {
        $event = AssetEvent::query()->create([
            'asset_id' => Asset::factory()->create()->id,
            'event' => AssetEventType::Created,
            'user_id' => User::factory()->create()->id,
        ]);

        expect(fn () => $event->update(['notes' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $event->delete())->toThrow(LogicException::class);
    });

    it('AssetMaintenanceRecord no se modifica ni se borra', function () {
        $record = AssetMaintenanceRecord::query()->create([
            'asset_id' => Asset::factory()->create()->id,
            'performed_on' => '2026-10-01',
            'description' => 'Revisión',
            'user_id' => User::factory()->create()->id,
        ]);

        expect(fn () => $record->update(['description' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => $record->delete())->toThrow(LogicException::class);
    });
});

describe('AppSetting (D-005)', function () {
    it('RN-030: la anticipación de vencimientos es 30 días por defecto y se puede cambiar', function () {
        expect(AppSetting::expiryAlertDays())->toBe(30);

        AppSetting::put(AppSetting::EXPIRY_ALERT_DAYS, 45);

        expect(AppSetting::expiryAlertDays())->toBe(45);
    });
});
