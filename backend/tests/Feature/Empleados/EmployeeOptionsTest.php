<?php

use App\Models\Employee;
use App\Models\Position;

/**
 * D-004 / FR-006 / US3-2: lista mínima de empleados activos para otros
 * módulos, sin datos personales (RN-022).
 */
it('FR-006: devuelve solo empleados activos con id, nombre completo y cargo, sin paginar', function () {
    actingAsRole('admin');
    $bombero = Position::factory()->create(['name' => 'Bombero']);
    $cabo = Position::factory()->inactive()->create(['name' => 'Cabo']);
    $zapata = Employee::factory()->for($bombero)->create(['first_name' => 'Ana', 'last_name' => 'Zapata']);
    $gomez = Employee::factory()->for($cabo)->create(['first_name' => 'Luis', 'last_name' => 'Gómez']);
    Employee::factory()->inactive()->create(['last_name' => 'Retirado']);

    $this->getJson('/api/employees/options')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $gomez->id, 'full_name' => 'Luis Gómez', 'position' => 'Cabo'],
            ['id' => $zapata->id, 'full_name' => 'Ana Zapata', 'position' => 'Bombero'],
        ]]);
});

it('US3-2 / SC-003: no expone documento, contacto ni tallas', function () {
    actingWithPermissions(['entregas.ver']);
    Employee::factory()->create([
        'document_number' => '80123456',
        'phone' => '3105551234',
        'email' => 'privado@example.com',
        'shirt_size' => 'XL',
    ]);

    $content = $this->getJson('/api/employees/options')->assertOk()->getContent();

    expect($content)->not->toContain('80123456')
        ->not->toContain('3105551234')
        ->not->toContain('privado@example.com')
        ->not->toContain('XL');
});

it('D-004: autoriza con empleados.ver, entregas.ver o reportes.ver', function (array $permissions, int $status) {
    Employee::factory()->create();
    actingWithPermissions($permissions);

    $this->getJson('/api/employees/options')->assertStatus($status);
})->with([
    'empleados.ver' => [['empleados.ver'], 200],
    'entregas.ver' => [['entregas.ver'], 200],
    'reportes.ver' => [['reportes.ver'], 200],
    'otros módulos' => [['activos.ver', 'inventario.ver', 'configuracion.ver', 'usuarios.ver'], 403],
    'sin permisos' => [[], 403],
]);

it('D-004: 401 sin sesión y el rol Consulta las obtiene sin acceder al módulo', function () {
    $this->getJson('/api/employees/options')->assertUnauthorized();

    actingAsRole('consulta');
    $this->getJson('/api/employees/options')->assertOk();
    $this->getJson('/api/employees')->assertForbidden();
});
