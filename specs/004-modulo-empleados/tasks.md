# Tasks: Módulo Empleados (004)

**Tests**: obligatorios (TDD).

## Backend
- [ ] T001 Bloque 004 de `esquema-bd` a "migrado"; migraciones `positions`, `companies`, `employees`, `users.employee_id` y migración de datos RN-040 (D-006)
- [ ] T002 Tests + modelos, factories, policies y endpoints de `/api/positions` y `/api/companies` (patrón 002) y `positions`/`companies` en `/api/configuracion/options`
- [ ] T003 Tests + precarga de cargos RN-035 en `ConfigurationSeeder` (solo si `positions` está vacía) y Consulta sin `empleados.ver` en `RoleSeeder` (RN-040)
- [ ] T004 Tests + `Employee` (modelo, factory, policy), requests (D-002), `EmployeeResource`, `EmployeeController` (index/show/store/update/status) en `tests/Feature/Empleados/EmployeesTest.php` (unicidad tipo+número, normalización, búsqueda sin tildes, 403 sin permisos, Consulta sin acceso, sin DELETE)
- [ ] T005 Tests + `GET /api/employees/options` (D-004: sin datos personales, permisos alternativos)
- [ ] T006 Tests + vínculo `users.employee_id` en Store/UpdateUserRequest y `UserResource` (D-005)

## Frontend
- [ ] T007 [P] `packages/app/features/empleados/{api,schemas}.ts` (+ tests); `configuracion/api.ts` + positions/companies
- [ ] T008 [P] Pestañas Cargos y Compañías en `ConfiguracionScreen` (CatalogTab)
- [ ] T009 `EmpleadosScreen` (tabla, búsqueda, filtros cargo/compañía/estado, activar/desactivar) y `EmployeeFormDialog` (documento, nombres, cargo, compañía, contacto, fecha de ingreso, tallas) + tests; página `/empleados` con `useRequireAuth('empleados.ver')`
- [ ] T010 Select opcional "Empleado vinculado" en `UserFormDialog` (solo con `empleados.ver`) + test

## Cierre
- [ ] T011 Suites completas, typecheck, Pint
- [ ] T012 Revisión de `especialista-seguridad` (Ley 1581) y correcciones
- [ ] T013 Verificación en navegador y `CLAUDE.md`
