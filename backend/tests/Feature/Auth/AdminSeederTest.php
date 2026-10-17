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

it('es idempotente: no falla en producción si el administrador ya existe', function () {
    User::factory()->create(['username' => 'admin']);
    config(['app.admin_initial_password' => null]);
    app()->detectEnvironment(fn () => 'production');

    (new DatabaseSeeder)->run();

    expect(User::query()->where('username', 'admin')->count())->toBe(1);
});
