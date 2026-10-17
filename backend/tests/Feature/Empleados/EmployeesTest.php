<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Módulo Empleados (004): RN-021 datos del empleado, RN-022 acceso con
 * empleados.ver / empleados.gestionar, D-002 documento, D-003 búsqueda.
 */
function validEmployeePayload(array $overrides = []): array
{
    return [
        'document_type' => 'CC',
        'document_number' => '1234567',
        'first_name' => 'Juan',
        'last_name' => 'Pérez',
        'position_id' => Position::factory()->create()->id,
        ...$overrides,
    ];
}

describe('listado (FR-001)', function () {
    it('FR-001: lista paginada por apellidos y nombres con cargo, compañía y usuario vinculado', function () {
        $admin = actingAsRole('admin');
        $teniente = Position::factory()->create(['name' => 'Teniente']);
        $turno = Company::factory()->create(['name' => 'Turno A']);
        $zapata = Employee::factory()->for($teniente)->create(['first_name' => 'Ana', 'last_name' => 'Zapata']);
        $gomezB = Employee::factory()->for($teniente)->for($turno)->create(['first_name' => 'Beatriz', 'last_name' => 'Gómez']);
        $gomezA = Employee::factory()->for($teniente)->create(['first_name' => 'Andrés', 'last_name' => 'Gómez']);
        $admin->forceFill(['employee_id' => $gomezB->id])->save();

        $response = $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data' => [[
                'id', 'document_type', 'document_number', 'first_name', 'last_name', 'full_name',
                'position' => ['id', 'name', 'is_active'], 'company', 'phone', 'email', 'hire_date',
                'shirt_size', 'pants_size', 'boot_size', 'glove_size', 'is_active', 'user', 'created_at', 'updated_at',
            ]], 'meta', 'links']);

        expect(array_column($response->json('data'), 'id'))->toBe([$gomezA->id, $gomezB->id, $zapata->id])
            ->and($response->json('data.1.full_name'))->toBe('Beatriz Gómez')
            ->and($response->json('data.1.company'))->toBe(['id' => $turno->id, 'name' => 'Turno A', 'is_active' => true])
            ->and($response->json('data.1.user'))->toBe(['id' => $admin->id, 'username' => $admin->username])
            ->and($response->json('data.0.company'))->toBeNull()
            ->and($response->json('data.0.user'))->toBeNull()
            ->and($response->json('data.1.has_user'))->toBeTrue()
            ->and($response->json('data.0.has_user'))->toBeFalse();
    });

    it('E-4: sin usuarios.ver no se expone el username del usuario vinculado', function () {
        actingWithPermissions(['empleados.ver']);
        $employee = Employee::factory()->create();
        User::factory()->create()->forceFill(['employee_id' => $employee->id])->save();

        $this->getJson("/api/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.user', null)
            ->assertJsonPath('data.has_user', true);
    });

    it('D-003: busca por nombre, apellido, nombre completo o documento sin distinguir mayúsculas ni tildes', function () {
        actingAsRole('admin');
        Employee::factory()->create(['first_name' => 'José Ángel', 'last_name' => 'Muñoz Peña', 'document_number' => '80123456']);
        Employee::factory()->create(['first_name' => 'Laura', 'last_name' => 'Rodríguez', 'document_type' => 'PA', 'document_number' => 'AB12345']);

        $names = fn (string $search) => array_column($this->getJson('/api/employees?search='.urlencode($search))->assertOk()->json('data'), 'last_name');

        expect($names('jose angel'))->toBe(['Muñoz Peña'])
            ->and($names('MUNOZ'))->toBe(['Muñoz Peña'])
            ->and($names('josé ángel muñoz'))->toBe(['Muñoz Peña'])
            ->and($names('rodriguez laura'))->toBe(['Rodríguez'])
            ->and($names('8012'))->toBe(['Muñoz Peña'])
            ->and($names('80.123.456'))->toBe(['Muñoz Peña'])
            ->and($names('ab123'))->toBe(['Rodríguez'])
            ->and($names('100%'))->toBe([]);
    });

    it('FR-001 / US1-4: filtra por cargo, compañía y estado; los inactivos siguen consultables', function () {
        actingAsRole('admin');
        $cabo = Position::factory()->create();
        $turno = Company::factory()->create();
        Employee::factory()->for($cabo)->create();
        Employee::factory()->for($turno)->create();
        $retirado = Employee::factory()->inactive()->create();

        $this->getJson("/api/employees?position_id={$cabo->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/employees?company_id={$turno->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/employees?is_active=0')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $retirado->id);
        $this->getJson('/api/employees?is_active=1')->assertJsonCount(2, 'data');
        $this->getJson("/api/employees/{$retirado->id}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/employees?position_id=abc&per_page=500')->assertJsonValidationErrors(['position_id', 'per_page']);
    });
});

describe('registro y edición (FR-002, FR-003)', function () {
    it('US1-1: registra un empleado activo con los datos obligatorios', function () {
        actingAsRole('admin');
        $payload = validEmployeePayload();

        $this->postJson('/api/employees', $payload)
            ->assertCreated()
            ->assertJsonPath('data.document_type', 'CC')
            ->assertJsonPath('data.document_number', '1234567')
            ->assertJsonPath('data.full_name', 'Juan Pérez')
            ->assertJsonPath('data.position.id', $payload['position_id'])
            ->assertJsonPath('data.company', null)
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.user', null)
            ->assertJsonPath('data.is_active', true);
    });

    it('FR-003: guarda los datos opcionales de contacto, ingreso y tallas', function () {
        actingAsRole('admin');
        $turno = Company::factory()->create();

        $this->postJson('/api/employees', validEmployeePayload([
            'company_id' => $turno->id,
            'phone' => '+57 (310) 555-1234',
            'email' => 'juan.perez@example.com',
            'hire_date' => '2020-02-29',
            'shirt_size' => 'M',
            'pants_size' => '32x30',
            'boot_size' => '42',
            'glove_size' => 'L',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.company.id', $turno->id)
            ->assertJsonPath('data.phone', '+57 (310) 555-1234')
            ->assertJsonPath('data.email', 'juan.perez@example.com')
            ->assertJsonPath('data.hire_date', '2020-02-29')
            ->assertJsonPath('data.pants_size', '32x30')
            ->assertJsonPath('data.boot_size', '42');
    });

    it('D-002: el número de documento se guarda solo con letras y dígitos en mayúsculas', function () {
        actingAsRole('admin');

        $this->postJson('/api/employees', validEmployeePayload(['document_number' => ' 1.234.567 ']))
            ->assertCreated()
            ->assertJsonPath('data.document_number', '1234567');
        $this->postJson('/api/employees', validEmployeePayload(['document_type' => 'pa', 'document_number' => 'ab-123 45']))
            ->assertCreated()
            ->assertJsonPath('data.document_type', 'PA')
            ->assertJsonPath('data.document_number', 'AB12345');
        $this->postJson('/api/employees', validEmployeePayload(['document_number' => '. -']))
            ->assertJsonPath('errors.document_number.0', 'El campo número de documento es obligatorio.');
    });

    it('US1-2 / FR-002: la unicidad es tipo + número, incluidos los inactivos', function () {
        actingAsRole('admin');
        Employee::factory()->inactive()->create(['document_type' => 'CC', 'document_number' => '1234567']);

        $this->postJson('/api/employees', validEmployeePayload(['document_number' => '1.234.567']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.document_number.0', 'El valor del campo número de documento ya está en uso.');
        $this->postJson('/api/employees', validEmployeePayload(['document_type' => 'CE']))->assertCreated();
    });

    it('FR-003: valida obligatorios, tipo de documento y longitudes', function () {
        actingAsRole('admin');

        $this->postJson('/api/employees', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['document_type', 'document_number', 'first_name', 'last_name', 'position_id']);
        $this->postJson('/api/employees', validEmployeePayload(['document_type' => 'NIT']))
            ->assertJsonPath('errors.document_type.0', 'El valor seleccionado en tipo de documento no es válido.');
        $this->postJson('/api/employees', validEmployeePayload([
            'document_number' => str_repeat('9', 21),
            'first_name' => str_repeat('a', 81),
            'shirt_size' => str_repeat('X', 11),
        ]))->assertJsonValidationErrors(['document_number', 'first_name', 'shirt_size']);
    });

    it('Edge cases: correo y teléfono con formato válido; fecha de ingreso no futura', function () {
        actingAsRole('admin');
        Carbon::setTestNow('2026-10-17 12:00:00');

        $this->postJson('/api/employees', validEmployeePayload([
            'email' => 'no-es-correo',
            'phone' => '310-ABC-1234',
            'hire_date' => '2026-10-18',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'phone', 'hire_date']);
        $this->postJson('/api/employees', validEmployeePayload(['phone' => '( )']))->assertJsonValidationErrors('phone');
        $this->postJson('/api/employees', validEmployeePayload(['phone' => str_repeat('1', 31)]))->assertJsonValidationErrors('phone');
        $this->postJson('/api/employees', validEmployeePayload(['hire_date' => '17/10/2026']))->assertJsonValidationErrors('hire_date');
        $this->postJson('/api/employees', validEmployeePayload(['hire_date' => '2026-10-17']))->assertCreated();
    });

    it('Edge cases: la fecha de ingreso se compara con el día de Colombia, no el de UTC', function () {
        actingAsRole('admin');
        // 2026-10-18 01:00 UTC = 2026-10-17 20:00 en America/Bogota.
        Carbon::setTestNow(Carbon::parse('2026-10-18 01:00:00', 'UTC'));

        $this->postJson('/api/employees', validEmployeePayload(['hire_date' => '2026-10-18']))
            ->assertJsonValidationErrors('hire_date');
    });

    it('US2-1 / FR-004: exige cargo y compañía existentes y activos', function () {
        actingAsRole('admin');
        $inactivo = Position::factory()->inactive()->create();
        $turnoCerrado = Company::factory()->inactive()->create();

        $this->postJson('/api/employees', validEmployeePayload(['position_id' => $inactivo->id]))
            ->assertJsonPath('errors.position_id.0', 'El cargo seleccionado está inactivo.');
        $this->postJson('/api/employees', validEmployeePayload(['position_id' => 999999]))
            ->assertJsonPath('errors.position_id.0', 'El valor seleccionado en cargo no es válido.');
        $this->postJson('/api/employees', validEmployeePayload(['company_id' => $turnoCerrado->id]))
            ->assertJsonPath('errors.company_id.0', 'La compañía seleccionada está inactiva.');
    });

    it('US2-1: al editar conserva su cargo y compañía inactivos, pero no cambia a otros inactivos', function () {
        actingAsRole('admin');
        $teniente = Position::factory()->inactive()->create();
        $otroInactivo = Position::factory()->inactive()->create();
        $turno = Company::factory()->inactive()->create();
        $employee = Employee::factory()->for($teniente)->for($turno)->create();

        $this->patchJson("/api/employees/{$employee->id}", [
            'position_id' => $teniente->id,
            'company_id' => $turno->id,
            'first_name' => 'Carlos',
        ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Carlos')
            ->assertJsonPath('data.position.is_active', false)
            ->assertJsonPath('data.company.is_active', false);

        $this->patchJson("/api/employees/{$employee->id}", ['position_id' => $otroInactivo->id])
            ->assertJsonValidationErrors('position_id');
        $this->patchJson("/api/employees/{$employee->id}", ['company_id' => null])
            ->assertOk()
            ->assertJsonPath('data.company', null);
    });

    it('FR-002: al editar valida la unicidad con el tipo y número resultantes', function () {
        actingAsRole('admin');
        Employee::factory()->create(['document_type' => 'CE', 'document_number' => '555']);
        $employee = Employee::factory()->create(['document_type' => 'CC', 'document_number' => '555']);

        $this->patchJson("/api/employees/{$employee->id}", ['document_type' => 'CE'])
            ->assertJsonValidationErrors('document_number');
        $this->patchJson("/api/employees/{$employee->id}", ['document_number' => '5.5.5', 'last_name' => 'Ríos'])
            ->assertOk()
            ->assertJsonPath('data.document_number', '555')
            ->assertJsonPath('data.last_name', 'Ríos');
        $this->patchJson("/api/employees/{$employee->id}", ['first_name' => ''])->assertJsonValidationErrors('first_name');
    });

    it('B-4: un documento duplicado detectado por la base de datos responde 422', function () {
        actingAsRole('admin');
        Employee::creating(function (Employee $employee) {
            DB::table('employees')->insert([
                'document_type' => $employee->document_type,
                'document_number' => $employee->document_number,
                'first_name' => 'Otro',
                'last_name' => 'Registro',
                'position_id' => $employee->position_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson('/api/employees', validEmployeePayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.document_number.0', 'El valor del campo número de documento ya está en uso.');
    });

    it('US1-4 / US1-5 / RN-021: desactiva y reactiva; no existe DELETE (405)', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create();

        $this->patchJson("/api/employees/{$employee->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/employees/{$employee->id}/status", ['is_active' => true])
            ->assertJsonPath('data.is_active', true);
        $this->patchJson("/api/employees/{$employee->id}/status", [])->assertJsonValidationErrors('is_active');

        $this->deleteJson("/api/employees/{$employee->id}")->assertMethodNotAllowed();
        expect(Employee::query()->whereKey($employee->id)->exists())->toBeTrue();
    });
});

describe('protección de datos personales (RN-022)', function () {
    it('US3-1 / RN-040: Consulta y los roles sin empleados.ver no consultan empleados por ninguna vía', function (?string $role, array $permissions) {
        $employee = Employee::factory()->create();
        $role !== null ? actingAsRole($role) : actingWithPermissions($permissions);

        $this->getJson('/api/employees')->assertForbidden();
        $this->getJson("/api/employees/{$employee->id}")->assertForbidden();
        $this->postJson('/api/employees', validEmployeePayload())->assertForbidden();
        $this->patchJson("/api/employees/{$employee->id}", ['first_name' => 'X'])->assertForbidden();
        $this->patchJson("/api/employees/{$employee->id}/status", ['is_active' => false])->assertForbidden();
    })->with([
        'Consulta' => ['consulta', []],
        'sin permisos' => [null, []],
        'entregas y reportes' => [null, ['entregas.ver', 'entregas.gestionar', 'reportes.ver']],
    ]);

    it('RN-022: 401 sin sesión; con ver consulta pero no modifica sin gestionar', function () {
        $employee = Employee::factory()->create();

        $this->getJson('/api/employees')->assertUnauthorized();

        actingAsRole('almacenista');
        $this->getJson('/api/employees')->assertOk();
        $this->getJson("/api/employees/{$employee->id}")->assertOk();
        $this->postJson('/api/employees', validEmployeePayload())->assertForbidden();
        $this->patchJson("/api/employees/{$employee->id}", ['first_name' => 'X'])->assertForbidden();
        $this->patchJson("/api/employees/{$employee->id}/status", ['is_active' => false])->assertForbidden();

        actingWithPermissions(['empleados.ver', 'empleados.gestionar']);
        $this->patchJson("/api/employees/{$employee->id}", ['first_name' => 'Ximena'])->assertOk();
    });

    it('RN-014: exige haber cambiado la contraseña temporal', function () {
        actingAsRole('admin', ['must_change_password' => true]);

        $this->getJson('/api/employees')->assertForbidden();
    });
});

it('RN-015: Employee::user() devuelve el usuario vinculado', function () {
    seedRbac();
    $employee = Employee::factory()->create();
    $user = User::factory()->create();
    $user->forceFill(['employee_id' => $employee->id])->save();

    expect($employee->user->is($user))->toBeTrue()
        ->and($employee->full_name)->toBe("{$employee->first_name} {$employee->last_name}");
});
