<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * RN-014 / FR-009: con la contraseña temporal pendiente de cambio, el usuario
 * solo puede consultar su sesión, cambiar la contraseña o cerrar sesión.
 */

it('RN-014: bloquea las rutas protegidas con 403 password_change_required', function () {
    actingAsRole('admin', ['must_change_password' => true]);

    $this->getJson('/api/users')
        ->assertForbidden()
        ->assertExactJson([
            'message' => 'Debes cambiar tu contraseña.',
            'code' => 'password_change_required',
        ]);

    $this->getJson('/api/roles')->assertForbidden()->assertJsonPath('code', 'password_change_required');
});

it('RN-014: permite consultar la sesión y cerrar sesión con el cambio pendiente', function () {
    $user = actingAsRole('consulta', ['must_change_password' => true]);

    $this->getJson('/api/user')->assertOk()->assertJsonPath('user.must_change_password', true);

    $token = $user->createToken('android')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->postJson('/api/logout')->assertOk();
});

it('RN-014: deja pasar a quien ya cambió la contraseña', function () {
    actingAsRole('admin');

    $this->getJson('/api/users')->assertOk();
});

it('RN-014: protege todas las rutas autenticadas salvo sesión, cambio de contraseña y logout', function () {
    $allowed = ['GET api/user', 'PUT api/user/password', 'POST api/logout'];

    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->reject(fn (RoutingRoute $route) => in_array('password.changed', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first().' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toEqualCanonicalizing($allowed);
});
