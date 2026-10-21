<?php

use App\Enums\TrackingMode;
use App\Models\AssetType;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 005 de esquema-bd (RN-005, RN-008, RN-028..RN-031) y disco de fotos
 * (T001, FR-008).
 */
function insertRawAsset(array $attributes = []): int
{
    $typeId = $attributes['asset_type_id'] ?? AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized])->id;

    return DB::table('assets')->insertGetId([
        'asset_type_id' => $typeId,
        'plate' => 'B31-'.fake()->unique()->numerify('####'),
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

function insertRawEvent(int $assetId): int
{
    return DB::table('asset_events')->insertGetId([
        'asset_id' => $assetId, 'event' => 'created', 'user_id' => User::factory()->create()->id,
    ]);
}

describe('T001: almacenamiento de fotos', function () {
    it('FR-008: ASSETS_DISK es local por defecto y el disco local sirve URLs temporales', function () {
        expect(config('filesystems.assets_disk'))->toBe('local')
            ->and(config('filesystems.disks.local.serve'))->toBeTrue()
            ->and(config('filesystems.disks.local.visibility'))->toBe('private');
    });

    // Decisiones 44 y 45 (2026-10-21): Backblaze B2 con buckets privados
    // separados para fotos y firmas. aws-sdk-php manda CRC32 por defecto y B2
    // lo rechaza: checksums solo cuando la operación los exige.
    it('FR-008 / decisión 44: los discos b2 y b2_firmas son S3 compatibles con Backblaze B2 y privados', function (string $disk) {
        expect(config("filesystems.disks.{$disk}"))
            ->driver->toBe('s3')
            ->use_path_style_endpoint->toBeTrue()
            ->request_checksum_calculation->toBe('when_required')
            ->response_checksum_validation->toBe('when_required')
            ->visibility->toBe('private')
            ->throw->toBeTrue();
    })->with(['b2', 'b2_firmas']);

    it('decisión 44: el disco r2 de Cloudflare ya no existe', function () {
        expect(config('filesystems.disks.r2'))->toBeNull();
    });

    it('decisión 45: SIGNATURES_DISK es por defecto el mismo disco de las fotos (local)', function () {
        expect(config('filesystems.signatures_disk'))->toBe('local');
    });
});

describe('T002: tablas', function () {
    it('crea assets, asset_events, planes, registros y app_settings sin softDeletes', function () {
        expect(Schema::hasColumns('assets', [
            'id', 'asset_type_id', 'plate', 'serial', 'brand', 'model', 'acquisition_date', 'acquisition_value',
            'useful_life_months', 'expires_on', 'status', 'location_id', 'holder_employee_id', 'photo_path',
            'notes', 'created_at', 'updated_at',
        ]))->toBeTrue()
            ->and(Schema::hasColumn('assets', 'deleted_at'))->toBeFalse()
            ->and(Schema::hasColumns('asset_events', [
                'id', 'asset_id', 'event', 'from_status', 'to_status', 'from_location_id', 'to_location_id',
                'employee_id', 'delivery_id', 'user_id', 'notes', 'changes', 'created_at',
            ]))->toBeTrue()
            ->and(Schema::hasColumn('asset_events', 'updated_at'))->toBeFalse()
            ->and(Schema::hasColumns('asset_maintenance_plans', [
                'id', 'asset_id', 'name', 'interval_months', 'last_done_on', 'next_due_on', 'alert_days',
                'is_active', 'created_at', 'updated_at',
            ]))->toBeTrue()
            ->and(Schema::hasColumns('asset_maintenance_records', [
                'id', 'asset_id', 'plan_id', 'performed_on', 'description', 'performed_by', 'cost', 'user_id', 'created_at',
            ]))->toBeTrue()
            ->and(Schema::hasColumn('asset_maintenance_records', 'updated_at'))->toBeFalse()
            ->and(Schema::hasColumns('app_settings', ['key', 'value', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumn('asset_types', 'min_stock'))->toBeTrue();
    });

    it('RN-005: un activo nace disponible y solo admite los cuatro estados', function () {
        $id = insertRawAsset();

        expect(DB::table('assets')->where('id', $id)->value('status'))->toBe('available');
        expect(fn () => inSavepoint(fn () => DB::table('assets')->where('id', $id)->update(['status' => 'perdido'])))
            ->toThrow(QueryException::class, 'assets_status_check');
    });

    it('RN-008: la placa es única sin distinguir mayúsculas', function () {
        insertRawAsset(['plate' => 'B31-0001']);

        expect(fn () => inSavepoint(fn () => insertRawAsset(['plate' => 'b31-0001'])))
            ->toThrow(UniqueConstraintViolationException::class, 'assets_plate_lower_unique');
    });

    it('RN-039: un activo asignado tiene poseedor y uno no asignado no lo tiene', function () {
        $id = insertRawAsset();

        expect(fn () => inSavepoint(fn () => DB::table('assets')->where('id', $id)->update(['status' => 'assigned'])))
            ->toThrow(QueryException::class, 'assets_holder_status_check');
    });

    it('rechaza valores negativos y vida útil o periodicidad no positivas', function () {
        expect(fn () => inSavepoint(fn () => insertRawAsset(['acquisition_value' => -1])))
            ->toThrow(QueryException::class, 'assets_acquisition_value_check');
        expect(fn () => inSavepoint(fn () => insertRawAsset(['useful_life_months' => 0])))
            ->toThrow(QueryException::class, 'assets_useful_life_months_check');

        $assetId = insertRawAsset();
        expect(fn () => inSavepoint(fn () => DB::table('asset_maintenance_plans')->insert([
            'asset_id' => $assetId, 'name' => 'Revisión', 'interval_months' => 0, 'next_due_on' => '2027-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'asset_maintenance_plans_interval_months_check');
    });

    it('RN-030: un plan tiene 30 días de anticipación por defecto y está activo', function () {
        $assetId = insertRawAsset();
        $planId = DB::table('asset_maintenance_plans')->insertGetId([
            'asset_id' => $assetId, 'name' => 'Revisión', 'interval_months' => 12, 'next_due_on' => '2027-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = DB::table('asset_maintenance_plans')->find($planId);
        expect($plan->alert_days)->toBe(30)->and($plan->is_active)->toBeTrue();
    });

    it('RN-028 / D-008: el stock mínimo es ≥ 0 y solo en tipos por cantidad', function () {
        $quantity = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);
        $serialized = AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized]);

        DB::table('asset_types')->where('id', $quantity->id)->update(['min_stock' => 10]);
        expect(DB::table('asset_types')->where('id', $quantity->id)->value('min_stock'))->toBe(10);

        expect(fn () => inSavepoint(fn () => DB::table('asset_types')->where('id', $quantity->id)->update(['min_stock' => -1])))
            ->toThrow(QueryException::class, 'asset_types_min_stock_check');
        expect(fn () => inSavepoint(fn () => DB::table('asset_types')->where('id', $serialized->id)->update(['min_stock' => 5])))
            ->toThrow(QueryException::class, 'asset_types_min_stock_check');
    });
});

describe('T002: inmutabilidad (FR-004, FR-005, SC-003)', function () {
    it('asset_events rechaza UPDATE, DELETE y TRUNCATE directos', function () {
        $eventId = insertRawEvent(insertRawAsset());

        expect(fn () => inSavepoint(fn () => DB::table('asset_events')->where('id', $eventId)->update(['notes' => 'x'])))
            ->toThrow(QueryException::class, 'solo inserción');
        expect(fn () => inSavepoint(fn () => DB::table('asset_events')->where('id', $eventId)->delete()))
            ->toThrow(QueryException::class, 'solo inserción');
        expect(fn () => inSavepoint(fn () => DB::statement('TRUNCATE asset_events')))
            ->toThrow(QueryException::class, 'solo inserción');

        expect(DB::table('asset_events')->where('id', $eventId)->value('notes'))->toBeNull();
    });

    it('asset_maintenance_records rechaza UPDATE, DELETE y TRUNCATE directos', function () {
        $recordId = DB::table('asset_maintenance_records')->insertGetId([
            'asset_id' => insertRawAsset(), 'performed_on' => '2026-10-01', 'description' => 'Prueba hidrostática',
            'user_id' => User::factory()->create()->id,
        ]);

        expect(fn () => inSavepoint(fn () => DB::table('asset_maintenance_records')->where('id', $recordId)->update(['description' => 'x'])))
            ->toThrow(QueryException::class, 'solo inserción');
        expect(fn () => inSavepoint(fn () => DB::table('asset_maintenance_records')->where('id', $recordId)->delete()))
            ->toThrow(QueryException::class, 'solo inserción');
        expect(fn () => inSavepoint(fn () => DB::statement('TRUNCATE asset_maintenance_records')))
            ->toThrow(QueryException::class, 'solo inserción');
    });
});
