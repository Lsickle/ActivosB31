<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

function roleId(string $code): int
{
    return Role::query()->where('code', $code)->value('id');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function newUserPayload(array $overrides = []): array
{
    return [
        'name' => 'Juana Pérez',
        'username' => 'jperez',
        'email' => 'jperez@b31.co',
        'role_id' => roleId('almacenista'),
        'password' => 'temporal-123',
        'password_confirmation' => 'temporal-123',
        ...$overrides,
    ];
}

it('RN-014: crea un usuario activo, con su rol y cambio de contraseña obligatorio', function () {
    actingAsRole('admin');

    $response = $this->postJson('/api/users', newUserPayload(['username' => 'JPerez', 'email' => 'JPerez@B31.co']))
        ->assertCreated()
        ->assertJsonPath('data.username', 'jperez')
        ->assertJsonPath('data.email', 'jperez@b31.co')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.must_change_password', true)
        ->assertJsonPath('data.role.code', 'almacenista');

    $user = User::query()->findOrFail($response->json('data.id'));
    expect(Hash::check('temporal-123', $user->password))->toBeTrue();
});

it('FR-008: rechaza username o correo duplicados sin distinguir mayúsculas', function () {
    actingAsRole('admin');
    User::factory()->create(['username' => 'jperez', 'email' => 'jperez@b31.co']);

    $this->postJson('/api/users', newUserPayload(['username' => 'JPEREZ', 'email' => 'otro@b31.co']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.username.0', 'El valor del campo nombre de usuario ya está en uso.');

    $this->postJson('/api/users', newUserPayload(['username' => 'otro', 'email' => 'JPerez@B31.CO']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'El valor del campo correo ya está en uso.');
});

it('FR-008: rechaza un username con arroba o caracteres no permitidos', function (string $username) {
    actingAsRole('admin');

    $this->postJson('/api/users', newUserPayload(['username' => $username]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
})->with(['con arroba' => 'j@perez', 'con espacio' => 'j perez', 'muy corto' => 'jp']);

it('el mensaje de arroba en el username es claro', function () {
    actingAsRole('admin');

    $this->postJson('/api/users', newUserPayload(['username' => 'j@perez']))
        ->assertJsonPath('errors.username.0', 'El nombre de usuario no puede contener "@".');
});

it('permite crear un usuario sin correo', function () {
    actingAsRole('admin');

    $this->postJson('/api/users', newUserPayload(['email' => null]))
        ->assertCreated()
        ->assertJsonPath('data.email', null);
});

it('RN-013: exige un rol activo', function () {
    actingAsRole('admin');
    $inactive = Role::factory()->inactive()->create();

    $this->postJson('/api/users', newUserPayload(['role_id' => $inactive->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role_id');

    $this->postJson('/api/users', newUserPayload(['role_id' => null]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.role_id.0', 'El campo rol es obligatorio.');
});

it('FR-010: aplica la política de contraseña al crear', function () {
    actingAsRole('admin');

    $this->postJson('/api/users', newUserPayload(['password' => 'corta1', 'password_confirmation' => 'corta1']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('RN-009: niega crear y editar sin usuarios.gestionar', function () {
    actingAsRole('consulta');
    $user = User::factory()->create();

    $this->postJson('/api/users', newUserPayload())->assertForbidden();
    $this->patchJson("/api/users/{$user->id}", ['name' => 'Otro'])->assertForbidden();
    expect(User::query()->where('username', 'jperez')->exists())->toBeFalse()
        ->and($user->fresh()->name)->not->toBe('Otro');
});

it('edita nombre, username, correo y rol', function () {
    actingAsRole('admin');
    $user = User::factory()->create(['username' => 'jperez']);

    $this->patchJson("/api/users/{$user->id}", [
        'name' => 'Juana P.',
        'username' => 'JPerez',
        'email' => null,
        'role_id' => roleId('consulta'),
    ])->assertOk()
        ->assertJsonPath('data.name', 'Juana P.')
        ->assertJsonPath('data.username', 'jperez')
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.role.code', 'consulta');
});

it('FR-008: al editar rechaza el username de otro usuario', function () {
    actingAsRole('admin');
    User::factory()->create(['username' => 'ocupado']);
    $user = User::factory()->create();

    $this->patchJson("/api/users/{$user->id}", ['username' => 'OCUPADO'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('FR-012: impide quitar el rol Administrador al último administrador activo', function () {
    // RN-019: solo un Administrador gestiona usuarios, así que el último
    // administrador activo solo puede intentar quitarse el rol a sí mismo.
    $onlyAdmin = actingAsRole('admin');
    User::factory()->admin()->inactive()->create();

    $this->patchJson("/api/users/{$onlyAdmin->id}", ['role_id' => roleId('consulta')])
        ->assertConflict()
        ->assertJsonPath('message', 'Debe quedar al menos un Administrador activo.');

    expect($onlyAdmin->fresh()->role->code)->toBe('admin');
});

it('RN-017: permite editar un usuario conservando su rol inactivo', function () {
    actingAsRole('admin');
    $inactiveRole = Role::factory()->create();
    $user = User::factory()->inactive()->create(['role_id' => $inactiveRole->id]);
    $inactiveRole->forceFill(['is_active' => false])->save();

    $this->patchJson("/api/users/{$user->id}", ['name' => 'Renombrado', 'role_id' => $inactiveRole->id])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renombrado')
        ->assertJsonPath('data.role.id', $inactiveRole->id);
});

it('RN-017: no permite asignar un rol inactivo distinto del actual', function () {
    actingAsRole('admin');
    $user = User::factory()->create(['role_id' => roleId('consulta')]);
    $inactiveRole = Role::factory()->inactive()->create();

    $this->patchJson("/api/users/{$user->id}", ['role_id' => $inactiveRole->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role_id');
});

it('B-4: un duplicado detectado por la base de datos al crear responde 422', function () {
    actingAsRole('admin');
    // Simula la carrera: otra petición inserta el mismo username justo antes.
    User::creating(function (User $user) {
        DB::table('users')->insert([
            'name' => 'Concurrente', 'username' => $user->username, 'password' => 'x',
            'role_id' => roleId('consulta'),
        ]);
    });

    $this->postJson('/api/users', newUserPayload())
        ->assertUnprocessable()
        ->assertJsonPath('errors.username.0', 'El valor del campo nombre de usuario ya está en uso.');
});

it('B-4: un correo duplicado detectado por la base de datos al editar responde 422', function () {
    actingAsRole('admin');
    $user = User::factory()->create();
    User::updating(function (User $user) {
        DB::table('users')->insert([
            'name' => 'Concurrente', 'username' => 'concurrente', 'email' => $user->email, 'password' => 'x',
            'role_id' => roleId('consulta'),
        ]);
    });

    $this->patchJson("/api/users/{$user->id}", ['email' => 'nuevo@b31.co'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'El valor del campo correo ya está en uso.');
});

it('FR-012: permite quitar el rol Administrador si queda otro activo', function () {
    $actor = actingAsRole('admin');
    $other = User::factory()->admin()->create();

    $this->patchJson("/api/users/{$other->id}", ['role_id' => roleId('consulta')])->assertOk();
    $this->patchJson("/api/users/{$actor->id}", ['role_id' => roleId('consulta')])->assertConflict();
});
