<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bloque 004 de esquema-bd (RN-015, RN-021, RN-035, RN-036).
 */
it('RN-021: crea positions, companies y employees sin softDeletes', function () {
    foreach (['positions', 'companies'] as $table) {
        expect(Schema::hasColumns($table, ['id', 'name', 'description', 'is_active', 'created_at', 'updated_at']))->toBeTrue()
            ->and(Schema::hasColumn($table, 'deleted_at'))->toBeFalse();
    }

    expect(Schema::hasColumns('employees', [
        'id', 'document_type', 'document_number', 'first_name', 'last_name', 'position_id', 'company_id',
        'phone', 'email', 'hire_date', 'shirt_size', 'pants_size', 'boot_size', 'glove_size',
        'is_active', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('employees', 'deleted_at'))->toBeFalse();
});

it('FR-004: cargos y compañías tienen nombre único sin distinguir mayúsculas', function () {
    Position::factory()->create(['name' => 'Teniente']);
    expect(fn () => inSavepoint(fn () => Position::factory()->create(['name' => 'TENIENTE'])))
        ->toThrow(UniqueConstraintViolationException::class, 'positions_name_lower_unique');

    Company::factory()->create(['name' => 'Turno A']);
    expect(fn () => inSavepoint(fn () => Company::factory()->create(['name' => 'turno a'])))
        ->toThrow(UniqueConstraintViolationException::class, 'companies_name_lower_unique');
});

it('FR-002: la unicidad del documento es tipo + número', function () {
    Employee::factory()->create(['document_type' => 'CC', 'document_number' => '1234567']);
    Employee::factory()->create(['document_type' => 'CE', 'document_number' => '1234567']);

    expect(fn () => inSavepoint(fn () => Employee::factory()->create(['document_type' => 'CC', 'document_number' => '1234567'])))
        ->toThrow(UniqueConstraintViolationException::class, 'employees_document_type_number_unique');
});

it('FR-002: el tipo de documento solo admite CC, CE, TI, PA o PPT', function () {
    $employee = Employee::factory()->create();

    expect(fn () => inSavepoint(fn () => DB::table('employees')->where('id', $employee->id)->update(['document_type' => 'NIT'])))
        ->toThrow(QueryException::class);
});

it('RN-021: las FK son restrict: no se borra un cargo o compañía con empleados', function () {
    $employee = Employee::factory()->for(Company::factory())->create();

    expect(fn () => inSavepoint(fn () => DB::table('positions')->where('id', $employee->position_id)->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => inSavepoint(fn () => DB::table('companies')->where('id', $employee->company_id)->delete()))
        ->toThrow(QueryException::class);
});

it('RN-015: users.employee_id es opcional, único y restrict', function () {
    seedRbac();
    $employee = Employee::factory()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect($user->employee_id)->toBeNull();

    DB::table('users')->where('id', $user->id)->update(['employee_id' => $employee->id]);

    expect(fn () => inSavepoint(fn () => DB::table('users')->where('id', $other->id)->update(['employee_id' => $employee->id])))
        ->toThrow(UniqueConstraintViolationException::class, 'users_employee_id_unique');
    expect(fn () => inSavepoint(fn () => DB::table('employees')->where('id', $employee->id)->delete()))
        ->toThrow(QueryException::class);
});

it('RN-040 / D-006: la migración de datos quita empleados.ver al rol Consulta existente y es idempotente', function () {
    seedRbac();
    $consulta = Role::query()->where('code', 'consulta')->firstOrFail();
    $almacenista = Role::query()->where('code', 'almacenista')->firstOrFail();
    $custom = Role::factory()->create();
    $empleadosVer = Permission::query()->where('code', 'empleados.ver')->value('id');
    $empleadosGestionar = Permission::query()->where('code', 'empleados.gestionar')->value('id');
    // Estado de una instalación anterior a 004: Consulta tenía empleados.ver
    // (y, si alguien lo concedió en la matriz, empleados.gestionar).
    $consulta->permissions()->syncWithoutDetaching([$empleadosVer, $empleadosGestionar]);
    $custom->permissions()->sync([$empleadosVer]);

    $migration = require database_path('migrations/2026_10_17_500004_revoke_empleados_ver_from_consulta_role.php');
    $migration->up();
    $migration->up();

    // E-5: no queda "gestionar sin ver".
    expect($consulta->permissions()->pluck('code')->all())->not->toContain('empleados.ver')
        ->and($consulta->permissions()->pluck('code')->all())->not->toContain('empleados.gestionar')
        ->and($consulta->permissions()->pluck('code')->all())->toContain('entregas.ver', 'reportes.ver')
        ->and($almacenista->permissions()->pluck('code')->all())->toContain('empleados.ver')
        ->and($custom->permissions()->pluck('code')->all())->toBe(['empleados.ver']);
});

it('RN-040 / D-006: la migración de datos no falla sin roles ni permisos', function () {
    $migration = require database_path('migrations/2026_10_17_500004_revoke_empleados_ver_from_consulta_role.php');

    $migration->up();

    expect(Role::query()->count())->toBe(0);
});
