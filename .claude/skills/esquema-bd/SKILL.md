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

## PROPUESTA — pendiente de aprobación del usuario (2026-10-17)

> No migrar nada de esta sección hasta que el usuario la apruebe. Al aprobarse, cada bloque pasa a "migrado" con su feature.

### 003 — Seguridad: bitácora y bloqueo (A-1, RN-034)

```sql
users + failed_login_attempts smallint DEFAULT 0, locked_until timestamptz NULL   -- RN-034: 5 fallos seguidos → 15 min
security_events(id, event varchar(50),          -- login_succeeded, login_failed, account_locked, account_unlocked, logout,
                                                -- user_created, user_updated, user_status_changed, password_reset, password_changed,
                                                -- role_created, role_updated, role_status_changed, role_permissions_updated
                actor_id FK -> users NULL,      -- quién lo hizo (NULL en login fallido de cuenta inexistente)
                target_user_id FK -> users NULL, target_role_id FK -> roles NULL,
                login_attempted varchar(255) NULL,  -- texto ingresado en login fallido
                ip_address varchar(45) NULL, user_agent varchar(255) NULL,
                metadata jsonb NULL,            -- p. ej. {from_role, to_role}, permisos agregados/quitados
                created_at timestamptz idx)     -- SOLO INSERCIÓN: trigger que rechaza UPDATE/DELETE
```

### 004 — Empleados (RN-021, RN-022, RN-015)

```sql
positions(id, name varchar(120), description varchar(500) NULL, is_active, timestamps, UNIQUE(lower(name)))   -- cargos/rangos (Configuración)
companies(id, name varchar(120), description NULL, is_active, timestamps, UNIQUE(lower(name)))               -- compañías/turnos (Configuración)
employees(id, document_type varchar(5) CHECK IN ('CC','CE','TI','PA','PPT'), document_number varchar(20),
          first_name varchar(80), last_name varchar(80),
          position_id FK -> positions RESTRICT, company_id FK -> companies NULL RESTRICT,
          phone varchar(30) NULL, email varchar(255) NULL, hire_date date NULL,
          shirt_size varchar(10) NULL, pants_size varchar(10) NULL, boot_size varchar(10) NULL, glove_size varchar(10) NULL,
          is_active bool DEFAULT true, timestamps,
          UNIQUE(document_type, document_number))      -- no se borran: se desactivan
users + employee_id FK -> employees NULL UNIQUE     -- vínculo opcional (RN-015)
```

### 005 — Activos, mantenimientos y vencimientos (RN-001, RN-005..RN-008, RN-029..RN-031)

```sql
assets(id, asset_type_id FK -> asset_types RESTRICT,   -- solo tipos 'serialized' (RN-007)
       plate varchar(50), serial varchar(100) NULL,    -- RN-008: placa obligatoria y única (lower)
       brand varchar(80) NULL, model varchar(80) NULL,
       acquisition_date date NULL, acquisition_value numeric(14,2) NULL, useful_life_months smallint NULL,
       expires_on date NULL,                           -- RN-031
       status varchar(20) CHECK IN ('available','assigned','maintenance','retired') DEFAULT 'available',  -- RN-005
       location_id FK -> locations NULL,               -- dónde está (bodega, vehículo…)
       holder_employee_id FK -> employees NULL,        -- quién lo tiene (lo fija Entregas)
       photo_path varchar(255) NULL,                   -- R2
       notes text NULL, timestamps,
       UNIQUE(lower(plate)))
asset_events(id, asset_id FK, event varchar(30),       -- created, updated, status_changed, location_changed, delivered,
                                                       -- returned, maintenance_done, retired  (historial, SOLO INSERCIÓN)
             from_status NULL, to_status NULL, from_location_id NULL, to_location_id NULL,
             employee_id NULL, delivery_id NULL, user_id FK -> users, notes text NULL, created_at)
asset_maintenance_plans(id, asset_id FK, name varchar(120),          -- RN-029: por activo ("Prueba hidrostática")
                        interval_months smallint, last_done_on date NULL, next_due_on date,
                        alert_days smallint DEFAULT 30,              -- RN-030
                        is_active, timestamps)
asset_maintenance_records(id, asset_id FK, plan_id FK NULL, performed_on date, description varchar(500),
                          performed_by varchar(120) NULL, cost numeric(14,2) NULL, user_id, created_at)   -- SOLO INSERCIÓN
app_settings(key varchar(80) PK, value jsonb, updated_at)            -- expiry_alert_days = 30 (RN-030)
asset_types + min_stock integer NULL                                 -- RN-028, solo tipos 'quantity'
```

### 006 — Inventario (RN-027, RN-028) — artículos por cantidad

```sql
stock_levels(asset_type_id FK, location_id FK, quantity integer CHECK >= 0, updated_at, PK(asset_type_id, location_id))
inventory_movements(id, type varchar(20) CHECK IN ('entry','exit','transfer','adjustment','delivery','return','loss'),
                    asset_type_id FK, quantity integer CHECK > 0,
                    from_location_id NULL, to_location_id NULL,
                    delivery_id NULL, count_id NULL, reason varchar(500) NULL, user_id, created_at)   -- SOLO INSERCIÓN
inventory_counts(id, location_id FK, status CHECK IN ('open','closed'), notes NULL,
                 opened_by FK users, closed_by FK users NULL, opened_at, closed_at NULL)
inventory_count_lines(id, count_id FK, asset_type_id NULL, asset_id NULL,   -- cantidad: esperado/contado; serializado: encontrado
                      expected_quantity integer NULL, counted_quantity integer NULL, found bool NULL)
```

### 007 — Entregas (RN-023..RN-026)

```sql
deliveries(id, code varchar(20) UNIQUE,                 -- consecutivo ENT-000001
           employee_id FK RESTRICT, kind CHECK IN ('dotacion','prestamo'),   -- RN-023
           due_on date NULL,                            -- obligatoria si es préstamo
           from_location_id FK -> locations,            -- de dónde salen los artículos por cantidad
           signature_path varchar(255),                 -- RN-024, imagen en R2
           notes text NULL, delivered_by FK users, delivered_at timestamptz, created_at)   -- no se edita
delivery_items(id, delivery_id FK, asset_id FK NULL,    -- serializado
               asset_type_id FK, quantity integer CHECK > 0, returned_quantity integer DEFAULT 0)
delivery_returns(id, delivery_item_id FK, quantity integer CHECK > 0,
                 condition CHECK IN ('good','damaged','lost'),   -- RN-026
                 to_location_id FK NULL, notes NULL, received_by FK users, returned_at, created_at)
```

