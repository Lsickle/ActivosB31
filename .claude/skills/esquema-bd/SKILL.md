---
name: esquema-bd
description: Esquema de la base de datos de ActivosB31 (PostgreSQL 17) en formato DDL compacto, con relaciones y trazabilidad a reglas de negocio. Úsalo antes de crear o modificar modelos, migraciones, repositorios o cualquier código que dependa de la estructura de tablas. No asumas la estructura de una tabla de memoria — consulta este skill primero, y actualízalo en el mismo cambio que agregue o modifique una migración.
---

# Esquema de base de datos — ActivosB31

> **Estado**: migradas las tablas base y las de RBAC (001-rbac). Configuración está aprobada y pendiente de migrar; el resto de módulos está **pendiente de diseño** (ver `docs/modulos.md` y sus preguntas abiertas). Cada diseño se documenta aquí ANTES de escribir las migraciones, y el usuario lo aprueba.

## Convenciones

- PostgreSQL 17. Claves primarias `id bigint` autoincremental (`$table->id()`).
- `snake_case` en plural para tablas; FK como `<entidad>_id` con `constrained()`.
- `timestamps()` en todas las tablas de negocio. Usar `softDeletes()` en entidades con historial que no debe perderse (activos, empleados, entregas).
- Una sola organización: **no** hay columna `organization_id`/tenant.
- Los movimientos de inventario y las entregas son registros históricos: no se editan ni se borran físicamente; se corrigen con un movimiento inverso.

## Tablas existentes

```sql
-- Autenticación (0001_01_01_000000_create_users_table
--   + 2026_10_17_200003_add_role_and_password_flags_to_users_table)
users(id, name, username, email NULL, email_verified_at NULL, password,
      role_id FK -> roles NOT NULL idx,                 -- un rol por usuario (RN-013)
      is_active bool DEFAULT true,
      must_change_password bool DEFAULT false,          -- contraseña temporal (RN-014)
      last_login_at NULL, remember_token, timestamps,
      UNIQUE(lower(username)), UNIQUE(lower(email)) WHERE email IS NOT NULL,   -- FR-008
      CHECK (position('@' in username) = 0))            -- '@' reservado para el login por correo
      -- username y email se guardan en minúsculas (mutators de User). Sin vínculo con
      -- empleados por ahora (RN-015). No se borran: se desactivan.
password_reset_tokens(email PK, token, created_at)
sessions(id PK, user_id NULL idx, ip_address, user_agent, payload, last_activity idx)

-- Sanctum
personal_access_tokens(id, tokenable_type, tokenable_id, name, token UNIQUE, abilities,
                       last_used_at, expires_at, timestamps)

-- Infraestructura Laravel
cache, cache_locks, jobs, job_batches, failed_jobs
```

### RBAC (feature 001-rbac, migrado 2026-10-17)

Tablas propias, sin paquete externo (D-001). Rol único en `users.role_id`: **no existe** `user_roles` (RN-013).

```sql
roles(id, code varchar(50) NULL UNIQUE,      -- 'admin' | 'almacenista' | 'consulta' (precargados, RN-010); NULL en roles creados
      name varchar(80), description varchar(500) NULL,
      is_system bool DEFAULT false,          -- precargado: no se desactiva (FR-013)
      is_active bool DEFAULT true, timestamps,
      UNIQUE(lower(name)))
permissions(id, code varchar(80) UNIQUE,     -- '<modulo>.ver' | '<modulo>.gestionar' (RN-009)
            module varchar(40), action varchar(20) CHECK IN ('ver','gestionar'),
            name varchar(120), timestamps)
            -- catálogo fijo de 13 filas sincronizado por PermissionSeeder desde App\Enums\Permission; sin CRUD
role_permissions(role_id FK -> roles ON DELETE CASCADE,
                 permission_id FK -> permissions ON DELETE CASCADE idx,
                 PRIMARY KEY(role_id, permission_id))
```

Reglas asociadas:
- Módulos: activos, inventario, entregas, empleados, reportes (solo `ver`), configuracion, usuarios (cubre usuarios, roles y matriz).
- `gestionar` implica `ver` (FR-002), normalizado en el servidor al guardar la matriz.
- El rol `admin` tiene todos los permisos (`User::permissionCodes()`, sin `Gate::before`) y sus filas se re-sincronizan siempre en el seeder; no se edita desde la matriz.
- `usuarios.gestionar` es exclusivo del rol `admin` (RN-019): la matriz lo rechaza (422), el seeder lo quita de otros roles y no cuenta aunque exista la fila.
- Los roles `is_system` no se renombran (RN-020). El conteo de usuarios de un rol considera solo activos (RN-016).
- Siempre debe existir al menos un usuario activo con rol `admin` (FR-012); un rol con usuarios activos no se desactiva (FR-013).
- Al desactivar un usuario, restablecer o cambiar su contraseña se borran sus filas de `sessions` y `personal_access_tokens` (FR-011).

## Configuración (aprobado por el usuario, 2026-10-17 — pendiente de migrar)

Catálogos sin `softDeletes`: nunca se borran si tienen datos, solo se desactivan (RN-003). Nombres únicos sin distinguir mayúsculas (índice único sobre `lower(name)`).

```sql
asset_categories(id, name, description NULL, is_active bool DEFAULT true, timestamps,
                 UNIQUE(lower(name)))                                   -- RN-003
asset_types(id, asset_category_id FK -> asset_categories, name, description NULL,
            tracking_mode varchar CHECK IN ('serialized','quantity'),   -- RN-007
            is_active bool DEFAULT true, timestamps,
            UNIQUE(asset_category_id, lower(name)))                     -- RN-003
location_types(id, name, description NULL, is_active bool DEFAULT true, timestamps,
               UNIQUE(lower(name)))                                     -- RN-004
locations(id, location_type_id FK -> location_types, name, description NULL,
          is_active bool DEFAULT true, timestamps, UNIQUE(lower(name))) -- RN-002
```

Reglas asociadas:
- `asset_types.tracking_mode` no se puede cambiar si el tipo ya tiene activos (cuando exista la tabla de activos).
- Una categoría inactiva oculta sus tipos para registros nuevos (RN-003); no desactiva los tipos en cascada.
- La periodicidad de mantenimientos/vencimientos (RN-006) se diseña con el módulo de Activos; no está en estas tablas.

RBAC: ver la sección "RBAC (feature 001-rbac)" en Tablas existentes.

Activos, empleados, entregas e inventario: pendientes de las preguntas abiertas de `docs/modulos.md`.
