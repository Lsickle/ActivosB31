<?php

use App\Models\User;

it('responde 401 al pedir el usuario sin autenticar', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

it('responde 401 y no un error de ruta cuando no se pide JSON', function () {
    $this->get('/api/user')->assertUnauthorized();
});

it('devuelve el usuario autenticado con sus permisos', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('user.username', $user->username)
        ->assertJsonPath('user.permissions', [])
        ->assertJsonMissingPath('user.password');
});

it('bloquea la API a una cuenta desactivada con sesión ya iniciada', function () {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)->getJson('/api/user')->assertForbidden();
});

it('revoca el token Bearer al cerrar sesión', function () {
    $user = User::factory()->create();
    $token = $user->createToken('android')->plainTextToken;

    $this->withToken($token)->postJson('/api/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});
