<?php

use App\Enums\SecurityEventType;
use App\Models\Employee;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * RN-015 / D-005 (004): vínculo opcional uno a uno usuario → empleado,
 * gestionado desde Usuarios.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function linkedUserPayload(array $overrides = []): array
{
    return [
        'name' => 'Juana Pérez',
        'username' => 'jperez',
        'role_id' => Role::query()->where('code', Role::CONSULTA)->value('id'),
        'password' => 'temporal-123',
        'password_confirmation' => 'temporal-123',
        ...$overrides,
    ];
}

/**
 * Simula un actor con usuarios.gestionar pero sin empleados.ver: hoy solo el
 * Administrador gestiona usuarios (RN-019) y tiene todos los permisos
 * (RN-010), así que se niega la habilidad directamente.
 */
function denyEmployeeLinking(): void
{
    Gate::before(fn (User $user, string $ability) => $ability === 'linkEmployee' ? false : null);
}

describe('RN-015: alta con empleado vinculado', function () {
    it('crea el usuario vinculado a un empleado activo y lo expone en el recurso', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create(['first_name' => 'Juana', 'last_name' => 'Pérez']);

        $id = $this->postJson('/api/users', linkedUserPayload(['employee_id' => $employee->id]))
            ->assertCreated()
            ->assertJsonPath('data.employee', ['id' => $employee->id, 'full_name' => 'Juana Pérez'])
            ->json('data.id');

        expect(User::query()->findOrFail($id)->employee_id)->toBe($employee->id);
    });

    it('sin employee_id el usuario queda sin vínculo', function () {
        actingAsRole('admin');

        $this->postJson('/api/users', linkedUserPayload(['employee_id' => null]))
            ->assertCreated()
            ->assertJsonPath('data.employee', null);
    });

    it('rechaza un empleado inactivo o inexistente', function () {
        actingAsRole('admin');
        $inactive = Employee::factory()->inactive()->create();

        $this->postJson('/api/users', linkedUserPayload(['employee_id' => $inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');

        $this->postJson('/api/users', linkedUserPayload(['employee_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    });

    it('rechaza un empleado ya vinculado a otro usuario', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id]);

        $this->postJson('/api/users', linkedUserPayload(['employee_id' => $employee->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_id.0', 'El valor del campo empleado ya está en uso.');
    });

    it('B-4: un vínculo duplicado detectado por la base de datos responde 422', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create();
        // Carrera: otra petición vincula el mismo empleado justo antes.
        User::creating(function (User $user) {
            DB::table('users')->insert([
                'name' => 'Concurrente', 'username' => 'concurrente', 'password' => 'x',
                'role_id' => $user->role_id, 'employee_id' => $user->employee_id,
            ]);
        });

        $this->postJson('/api/users', linkedUserPayload(['employee_id' => $employee->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_id.0', 'El valor del campo empleado ya está en uso.');
    });

    it('A-1: user_created registra el nombre del empleado vinculado', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create(['first_name' => 'Juana', 'last_name' => 'Pérez']);

        $this->postJson('/api/users', linkedUserPayload(['employee_id' => $employee->id]))->assertCreated();

        expect(SecurityEvent::query()->where('event', SecurityEventType::UserCreated)->sole()->metadata)
            ->toMatchArray(['employee' => ['id' => $employee->id, 'name' => 'Juana Pérez']]);
    });

    it('A-1: user_created sin vínculo no incluye employee', function () {
        actingAsRole('admin');

        $this->postJson('/api/users', linkedUserPayload())->assertCreated();

        expect(SecurityEvent::query()->where('event', SecurityEventType::UserCreated)->sole()->metadata)->not->toHaveKey('employee');
    });

    it('403 si quien crea no tiene empleados.ver y envía un empleado', function () {
        actingAsRole('admin');
        denyEmployeeLinking();
        $employee = Employee::factory()->create();

        $this->postJson('/api/users', linkedUserPayload(['employee_id' => $employee->id]))->assertForbidden();
        $this->postJson('/api/users', linkedUserPayload(['employee_id' => null]))->assertCreated();
    });
});

describe('RN-015: edición del vínculo', function () {
    it('vincula, cambia y desvincula (null)', function () {
        actingAsRole('admin');
        $user = User::factory()->create();
        [$first, $second] = Employee::factory()->count(2)->create();

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => $first->id])
            ->assertOk()
            ->assertJsonPath('data.employee.id', $first->id);

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => $second->id])
            ->assertOk()
            ->assertJsonPath('data.employee.id', $second->id);

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => null])
            ->assertOk()
            ->assertJsonPath('data.employee', null);

        expect($user->fresh()->employee_id)->toBeNull();
    });

    it('conserva el empleado actual aunque esté inactivo', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        $employee->forceFill(['is_active' => false])->save();

        $this->patchJson("/api/users/{$user->id}", ['name' => 'Renombrado', 'employee_id' => $employee->id])
            ->assertOk()
            ->assertJsonPath('data.employee.id', $employee->id);
    });

    it('no permite vincular otro empleado inactivo', function () {
        actingAsRole('admin');
        $user = User::factory()->create();
        $inactive = Employee::factory()->inactive()->create();

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    });

    it('rechaza un empleado vinculado a otro usuario', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create();
        User::factory()->create(['employee_id' => $employee->id]);
        $user = User::factory()->create();

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => $employee->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    });

    it('B-4: un vínculo duplicado detectado por la base de datos al editar responde 422', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create();
        $user = User::factory()->create();
        User::updating(function (User $user) {
            DB::table('users')->insert([
                'name' => 'Concurrente', 'username' => 'concurrente', 'password' => 'x',
                'role_id' => $user->role_id, 'employee_id' => $user->employee_id,
            ]);
        });

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => $employee->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.employee_id.0', 'El valor del campo empleado ya está en uso.');
    });

    it('A-1: user_updated registra el empleado antes y después por nombre', function () {
        actingAsRole('admin');
        $before = Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Gómez']);
        $after = Employee::factory()->create(['first_name' => 'Luis', 'last_name' => 'Díaz']);
        $user = User::factory()->create(['employee_id' => $before->id]);

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => $after->id])->assertOk();
        $this->patchJson("/api/users/{$user->id}", ['employee_id' => null])->assertOk();

        $events = SecurityEvent::query()->where('event', SecurityEventType::UserUpdated)->orderBy('id')->get();
        expect($events)->toHaveCount(2)
            ->and($events[0]->metadata)->toBe(['changes' => ['employee' => [
                ['id' => $before->id, 'name' => 'Ana Gómez'],
                ['id' => $after->id, 'name' => 'Luis Díaz'],
            ]]])
            ->and($events[1]->metadata)->toBe(['changes' => ['employee' => [['id' => $after->id, 'name' => 'Luis Díaz'], null]]]);
    });

    it('403 si quien edita no tiene empleados.ver y cambia el vínculo; enviarlo sin cambios se permite', function () {
        actingAsRole('admin');
        $employee = Employee::factory()->create();
        $user = User::factory()->create(['employee_id' => $employee->id]);
        denyEmployeeLinking();

        $this->patchJson("/api/users/{$user->id}", ['employee_id' => null])->assertForbidden();
        $this->patchJson("/api/users/{$user->id}", ['employee_id' => Employee::factory()->create()->id])->assertForbidden();
        $this->patchJson("/api/users/{$user->id}", ['name' => 'Otro', 'employee_id' => $employee->id])->assertOk();

        expect($user->fresh()->employee_id)->toBe($employee->id);
    });
});

it('el listado y el detalle incluyen el empleado vinculado', function () {
    actingAsRole('admin');
    $employee = Employee::factory()->create(['first_name' => 'Juana', 'last_name' => 'Pérez']);
    $user = User::factory()->create(['employee_id' => $employee->id, 'username' => 'vinculado']);

    $this->getJson("/api/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('data.employee', ['id' => $employee->id, 'full_name' => 'Juana Pérez']);

    $this->getJson('/api/users?search=vinculado')
        ->assertOk()
        ->assertJsonPath('data.0.employee.full_name', 'Juana Pérez');
});
