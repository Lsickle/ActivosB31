<?php

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\Position;

/**
 * 008 — GET /api/reports/employee-options (agregado al contrato por el hilo
 * principal): incluye inactivos y devuelve solo nombre y cargo (RN-022).
 */
describe('GET /api/reports/employee-options', function () {
    it('exige reportes.ver', function () {
        actingWithPermissions([Permission::EntregasVer->value]);

        $this->getJson('/api/reports/employee-options?search=ana')->assertForbidden();
    });

    it('incluye inactivos, solo nombre y cargo, ordenados por apellido', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $position = Position::factory()->create(['name' => 'Bombero']);
        $active = Employee::factory()->for($position)->create(['first_name' => 'Ana', 'last_name' => 'Zapata']);
        $inactive = Employee::factory()->for($position)->inactive()->create(['first_name' => 'Anabel', 'last_name' => 'Alvarez']);
        Employee::factory()->create(['first_name' => 'Pedro', 'last_name' => 'Gómez']);

        $this->getJson('/api/reports/employee-options?search=ANA')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $inactive->id, 'full_name' => 'Anabel Alvarez', 'position' => 'Bombero', 'is_active' => false],
                ['id' => $active->id, 'full_name' => 'Ana Zapata', 'position' => 'Bombero', 'is_active' => true],
            ]]);
    });

    it('RN-022: no busca por número de documento (sin empleados.ver no se puede sondear)', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Rojas', 'document_number' => '80123456']);

        $this->getJson('/api/reports/employee-options?search=80123456')->assertOk()->assertExactJson(['data' => []]);
    });

    it('sin búsqueda devuelve una lista vacía y como máximo 20 resultados', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        Employee::factory()->count(22)->create(['first_name' => 'Carlos']);

        $this->getJson('/api/reports/employee-options')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/reports/employee-options?search=carlos')->assertOk()->assertJsonCount(20, 'data');
    });
});
