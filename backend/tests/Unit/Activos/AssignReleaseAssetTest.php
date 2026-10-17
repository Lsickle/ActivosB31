<?php

use App\Actions\Activos\AssignAsset;
use App\Actions\Activos\ReleaseAsset;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;

/**
 * Acciones que 005 expone para Entregas (007, D-001): solo ellas ponen y
 * quitan el estado Asignado (RN-005) y el poseedor (RN-039).
 */
describe('AssignAsset', function () {
    it('RN-039: asigna un activo disponible, fija el poseedor, conserva la ubicación y registra la entrega', function () {
        $user = User::factory()->create();
        $employee = Employee::factory()->create();
        $bodega = Location::factory()->create();
        $asset = Asset::factory()->at($bodega)->create();

        $result = app(AssignAsset::class)->handle($asset, $employee, $user, deliveryId: 42, notes: 'Dotación');

        expect($result->status)->toBe(AssetStatus::Assigned)
            ->and($result->holder_employee_id)->toBe($employee->id)
            ->and($result->location_id)->toBe($bodega->id)
            ->and($asset->status)->toBe(AssetStatus::Assigned);

        $fresh = $asset->fresh();
        expect($fresh->status)->toBe(AssetStatus::Assigned)
            ->and($fresh->holder_employee_id)->toBe($employee->id)
            ->and($fresh->location_id)->toBe($bodega->id);

        $event = $asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::Delivered)
            ->and($event->from_status)->toBe(AssetStatus::Available)
            ->and($event->to_status)->toBe(AssetStatus::Assigned)
            ->and($event->employee_id)->toBe($employee->id)
            ->and($event->delivery_id)->toBe(42)
            ->and($event->user_id)->toBe($user->id)
            ->and($event->notes)->toBe('Dotación');
    });

    it('la entrega es opcional en delivery_id', function () {
        $asset = Asset::factory()->create();

        app(AssignAsset::class)->handle($asset, Employee::factory()->create(), User::factory()->create());

        expect($asset->events()->sole()->delivery_id)->toBeNull();
    });

    it('D-001: solo asigna activos disponibles', function (string $status) {
        $asset = Asset::factory()->create(['status' => $status, 'holder_employee_id' => $status === 'assigned' ? Employee::factory()->create()->id : null]);

        expect(fn () => app(AssignAsset::class)->handle($asset, Employee::factory()->create(), User::factory()->create()))
            ->toThrow(AssetStateException::class, 'Solo se pueden entregar activos disponibles.');

        expect($asset->events()->count())->toBe(0);
    })->with(['assigned', 'maintenance', 'retired']);

    it('lee el estado vigente de la base de datos, no el de la instancia', function () {
        $asset = Asset::factory()->create();
        Asset::query()->whereKey($asset->id)->update(['status' => 'maintenance']);

        expect(fn () => app(AssignAsset::class)->handle($asset, Employee::factory()->create(), User::factory()->create()))
            ->toThrow(AssetStateException::class);
    });

    it('no entrega a un empleado inactivo (US1-4 de 004)', function () {
        $asset = Asset::factory()->create();

        expect(fn () => app(AssignAsset::class)->handle($asset, Employee::factory()->inactive()->create(), User::factory()->create()))
            ->toThrow(AssetStateException::class, 'El empleado está inactivo.');
    });
});

describe('ReleaseAsset', function () {
    it('devuelve a disponible en la ubicación de destino, quita el poseedor y registra la devolución', function () {
        $user = User::factory()->create();
        $employee = Employee::factory()->create();
        $bodega = Location::factory()->create();
        $taller = Location::factory()->create();
        $asset = Asset::factory()->at($bodega)->assignedTo($employee)->create();

        app(ReleaseAsset::class)->handle($asset, AssetStatus::Available, $taller, $user, deliveryId: 7, notes: 'Buen estado');

        $fresh = $asset->fresh();
        expect($fresh->status)->toBe(AssetStatus::Available)
            ->and($fresh->holder_employee_id)->toBeNull()
            ->and($fresh->location_id)->toBe($taller->id);

        $event = $asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::Returned)
            ->and($event->from_status)->toBe(AssetStatus::Assigned)
            ->and($event->to_status)->toBe(AssetStatus::Available)
            ->and($event->from_location_id)->toBe($bodega->id)
            ->and($event->to_location_id)->toBe($taller->id)
            ->and($event->employee_id)->toBe($employee->id)
            ->and($event->delivery_id)->toBe(7)
            ->and($event->notes)->toBe('Buen estado');
    });

    it('sin ubicación de destino conserva la actual; puede quedar en mantenimiento', function () {
        $bodega = Location::factory()->create();
        $asset = Asset::factory()->at($bodega)->assignedTo()->create();

        app(ReleaseAsset::class)->handle($asset, AssetStatus::Maintenance, null, User::factory()->create());

        expect($asset->fresh()->status)->toBe(AssetStatus::Maintenance)
            ->and($asset->fresh()->location_id)->toBe($bodega->id);
    });

    it('RN-005: puede devolverse dado de baja con motivo (dos eventos: devolución y baja)', function () {
        $asset = Asset::factory()->assignedTo()->create();

        expect(fn () => app(ReleaseAsset::class)->handle($asset, AssetStatus::Retired, null, User::factory()->create()))
            ->toThrow(InvalidArgumentException::class, 'La baja exige un motivo.');

        app(ReleaseAsset::class)->handle($asset, AssetStatus::Retired, null, User::factory()->create(), reason: 'Perdido en servicio');

        expect($asset->fresh()->status)->toBe(AssetStatus::Retired)
            ->and($asset->events()->orderBy('id')->pluck('event')->all())->toBe([AssetEventType::Returned, AssetEventType::Retired])
            ->and($asset->events()->where('event', 'retired')->value('notes'))->toBe('Perdido en servicio');
    });

    it('D-001: solo libera activos asignados y no a estado Asignado', function () {
        $available = Asset::factory()->create();

        expect(fn () => app(ReleaseAsset::class)->handle($available, AssetStatus::Available, null, User::factory()->create()))
            ->toThrow(AssetStateException::class, 'El activo no está asignado.');

        $assigned = Asset::factory()->assignedTo()->create();
        expect(fn () => app(ReleaseAsset::class)->handle($assigned, AssetStatus::Assigned, null, User::factory()->create()))
            ->toThrow(InvalidArgumentException::class);
    });

    it('no devuelve a una ubicación no disponible', function () {
        $asset = Asset::factory()->assignedTo()->create();

        expect(fn () => app(ReleaseAsset::class)->handle($asset, AssetStatus::Available, Location::factory()->inactive()->create(), User::factory()->create()))
            ->toThrow(InvalidArgumentException::class, 'La ubicación seleccionada está inactiva.');
    });
});
