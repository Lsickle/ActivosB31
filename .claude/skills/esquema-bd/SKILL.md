---
name: esquema-bd
description: Esquema de la base de datos de ActivosB31 (PostgreSQL 17) en formato DDL compacto, con relaciones y trazabilidad a reglas de negocio. Úsalo antes de crear o modificar modelos, migraciones, repositorios o cualquier código que dependa de la estructura de tablas. No asumas la estructura de una tabla de memoria — consulta este skill primero, y actualízalo en el mismo cambio que agregue o modifique una migración.
---

# Esquema de base de datos — ActivosB31

> **Estado**: migradas las tablas base, las de RBAC (001-rbac) y las de Configuración (002-modulo-configuracion); el resto de módulos está **pendiente de diseño** (ver `docs/modulos.md` y sus preguntas abiertas). Cada diseño se documenta aquí ANTES de escribir las migraciones, y el usuario lo aprueba.

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

## Configuración (feature 002-modulo-configuracion, migrado 2026-10-17)

Catálogos sin `softDeletes`: nunca se borran, solo se desactivan (RN-003); las FK son `RESTRICT` (D-010). Nombres únicos sin distinguir mayúsculas, incluidos los inactivos (FR-006). Longitudes: `name varchar(120)`, `description varchar(500)`.

```sql
-- 2026_10_17_300000_create_asset_categories_table
asset_categories(id, name varchar(120), description varchar(500) NULL,
                 is_active bool DEFAULT true, timestamps,
                 UNIQUE(lower(name)))                         -- asset_categories_name_lower_unique
-- 2026_10_17_300001_create_asset_types_table
asset_types(id, asset_category_id FK -> asset_categories RESTRICT NOT NULL idx,
            name varchar(120), description varchar(500) NULL,
            tracking_mode varchar(20) CHECK IN ('serialized','quantity'),   -- RN-007 (App\Enums\TrackingMode)
            is_active bool DEFAULT true, timestamps,
            UNIQUE(asset_category_id, lower(name)))           -- asset_types_category_name_lower_unique
-- 2026_10_17_300002_create_location_types_table
location_types(id, name varchar(120), description varchar(500) NULL,
               is_active bool DEFAULT true, timestamps,
               UNIQUE(lower(name)))                           -- location_types_name_lower_unique (RN-004)
-- 2026_10_17_300003_create_locations_table
locations(id, location_type_id FK -> location_types RESTRICT NOT NULL idx,
          name varchar(120), description varchar(500) NULL,
          is_active bool DEFAULT true, timestamps,
          UNIQUE(lower(name)))                                -- locations_name_lower_unique (RN-002: sin parent_id)
```

Reglas asociadas:
- `asset_types.tracking_mode` no se puede cambiar si el tipo ya tiene activos (FR-009; `AssetType::hasAssets()` devuelve `false` hasta que exista la tabla de activos).
- Una categoría inactiva oculta sus tipos para registros nuevos (RN-003, scope `AssetType::available()`); no desactiva los tipos en cascada. Igual con tipos de ubicación inactivos y sus ubicaciones (`Location::available()`).
- Precarga (RN-012, `ConfigurationSeeder`): categorías + tipos solo si `asset_categories` está vacía; tipos de ubicación solo si `location_types` está vacía (D-008). No se precargan ubicaciones.
- La periodicidad de mantenimientos/vencimientos (RN-006) se diseña con el módulo de Activos; no está en estas tablas.

RBAC: ver la sección "RBAC (feature 001-rbac)" en Tablas existentes.

Activos, empleados, entregas e inventario: pendientes de las preguntas abiertas de `docs/modulos.md`.
