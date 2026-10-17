<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('crea el administrador con la contraseña configurada', function () {
    config(['app.admin_initial_password' => 'clave-inicial-123']);

    $this->seed(DatabaseSeeder::class);

    $this->postJson('/api/login', ['login' => 'admin', 'password' => 'clave-inicial-123', 'device_name' => 'test'])
        ->assertOk();
});

it('no genera una contraseña en producción si falta ADMIN_INITIAL_PASSWORD', function () {
    config(['app.admin_initial_password' => null]);
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class);
    expect(User::query()->where('username', 'admin')->exists())->toBeFalse();
});

it('M-2: es idempotente: no crea el admin ni exige contraseña si ya hay un usuario Administrador', function () {
    seedRbac();
    User::factory()->admin()->create(['username' => 'jefe']);
    config(['app.admin_initial_password' => null]);
    app()->detectEnvironment(fn () => 'production');

    (new DatabaseSeeder)->run();

    expect(User::query()->where('username', 'admin')->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(1);
});

it('M-2: crea el admin inicial aunque exista otra cuenta si ninguna es Administrador', function () {
    seedRbac();
    User::factory()->create(['username' => 'bombero']);
    config(['app.admin_initial_password' => 'clave-inicial-123']);

    (new DatabaseSeeder)->run();

    expect(User::query()->where('username', 'admin')->firstOrFail()->isAdmin())->toBeTrue();
});

it('M-2: falla con un mensaje claro si "admin" existe sin rol Administrador y no hay ningún Administrador', function () {
    seedRbac();
    User::factory()->create(['username' => 'admin']);
    config(['app.admin_initial_password' => 'clave-inicial-123']);

    expect(fn () => (new DatabaseSeeder)->run())->toThrow(RuntimeException::class, 'admin');
});
