# Implementation Plan: Módulo Empleados

**Branch**: `004-modulo-empleados` (sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

## Summary

Catálogos `positions` (cargos, precargados RN-035) y `companies` (compañías/turnos, sin precarga RN-036) en Configuración, con el mismo patrón de 002 (`ConfigurationCatalogPolicy`, `CatalogTab`). Tabla `employees` con unicidad tipo+número de documento, CRUD sin borrado bajo `empleados.ver`/`empleados.gestionar` (RN-022), endpoint de opciones mínimas (id, nombre, cargo) para otros módulos, y vínculo opcional `users.employee_id` (RN-015) en el formulario de usuario. La precarga quita `empleados.ver` a Consulta (RN-040).

## Technical Context

PHP 8.4 / Laravel 13; Next.js 16 / React 19. **Sin dependencias nuevas.** PostgreSQL: `positions`, `companies`, `employees`, `users.employee_id` (bloque 004 de `esquema-bd`, aprobado).

## Constitution Check

| Principio | Estado |
|---|---|
| I. Esquema | ✅ Bloque 004 aprobado (pregunta 17). |
| II. Trazabilidad | ✅ RN-015, RN-021, RN-022, RN-035, RN-036, RN-040. |
| III. No reinterpretación | ✅ US3-2 (otros módulos ven solo nombre y cargo) se deriva de RN-022. |
| IV. TDD | ✅ |
| V. Seguridad | ⚠️ Obligatoria: datos personales (Ley 1581). Revisión de `especialista-seguridad` antes de cerrar. |

## Decisiones (research)

- **D-001 Catálogos**: `Position`/`Company` reutilizan `HasCatalogSearch`, `NormalizesCatalogFields`, `ListCatalogRequest`, `UpdateCatalogStatusRequest` y subclases de `ConfigurationCatalogPolicy`. Rutas `/api/positions`, `/api/companies`. `GET /api/configuracion/options` agrega `positions` y `companies` activos.
- **D-002 Documento**: `prepareForValidation` deja solo `[A-Z0-9]` en mayúsculas; `document_type` ∈ CC, CE, TI, PA, PPT; índice `UNIQUE(document_type, document_number)` → 422 en carrera (`TranslatesUniqueViolations`).
- **D-003 Búsqueda**: `search` sobre `first_name`, `last_name`, `document_number` y nombre completo, con `translate(lower())` (D-003 de 002). Orden `last_name, first_name, id`.
- **D-004 Opciones mínimas**: `GET /api/employees/options` (activos; `id, full_name, position`) autorizado con `empleados.ver` **o** `entregas.ver` **o** `reportes.ver` (FR-006, US3-2): no expone datos personales.
- **D-005 Vínculo usuario**: `users.employee_id` nullable unique; `StoreUserRequest`/`UpdateUserRequest` aceptan `employee_id` (empleado activo no vinculado a otro usuario; al editar puede conservar el actual). `UserResource` + `employee{id, full_name}`. En el formulario de usuario el select usa `/api/employees/options` y solo aparece si quien edita tiene `empleados.ver`.
- **D-006 RN-040**: `RoleSeeder` define Consulta con todos los `ver` excepto `empleados.ver`. Como la precarga solo asigna permisos al crear el rol, se agrega una migración de datos idempotente que quita `empleados.ver` al rol `consulta` existente (una sola vez, con `DB::table`), para que las instalaciones actuales cumplan la regla.
- **D-007 Bitácora**: no aplica (los cambios de empleados no son eventos de seguridad); timestamps bastan en esta versión.

## Contrato (resumen)

| Método | Ruta | Permiso | Notas |
|---|---|---|---|
| GET | `/api/employees` | empleados.ver | `search, position_id, company_id, is_active, page, per_page` |
| GET | `/api/employees/{id}` | empleados.ver | |
| POST | `/api/employees` | empleados.gestionar | campos de FR-003 |
| PATCH | `/api/employees/{id}` | empleados.gestionar | |
| PATCH | `/api/employees/{id}/status` | empleados.gestionar | `{is_active}` |
| GET | `/api/employees/options` | empleados.ver ∨ entregas.ver ∨ reportes.ver | `{data:[{id, full_name, position}]}` |
| CRUD | `/api/positions`, `/api/companies` | configuracion.* | patrón 002 |

`EmployeeResource`: `id, document_type, document_number, first_name, last_name, full_name, position{id,name,is_active}, company{id,name,is_active}|null, phone, email, hire_date, shirt_size, pants_size, boot_size, glove_size, is_active, user{id, username}|null, created_at, updated_at`.

## Estructura

```text
backend/app/Models/{Position,Company,Employee}.php · Policies/{Position,Company,Employee}Policy.php
backend/app/Http/Controllers/Api/{Position,Company,Employee}Controller.php · Requests/Empleados/* · Resources/*
backend/database/migrations/2026_10_17_5000xx_* · seeders/ConfigurationSeeder.php (+ cargos RN-035) · RoleSeeder.php (RN-040)
backend/tests/Feature/Empleados/*
frontend/packages/app/features/empleados/{api,schemas}.ts · features/configuracion/api.ts (+positions, companies)
frontend/apps/next/features/empleados/{EmpleadosScreen,EmployeeFormDialog}.tsx · features/configuracion/{Positions,Companies}Tab.tsx
frontend/apps/next/app/(dashboard)/empleados/page.tsx · features/usuarios/UserFormDialog.tsx (+ empleado)
```
