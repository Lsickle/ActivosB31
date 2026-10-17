---
name: esquema-bd
description: Esquema de la base de datos de ActivosB31 (PostgreSQL 17) en formato DDL compacto, con relaciones y trazabilidad a reglas de negocio. Úsalo antes de crear o modificar modelos, migraciones, repositorios o cualquier código que dependa de la estructura de tablas. No asumas la estructura de una tabla de memoria — consulta este skill primero, y actualízalo en el mismo cambio que agregue o modifique una migración.
---

# Esquema de base de datos — ActivosB31

> **Estado**: migradas las tablas base, las de RBAC (001-rbac), las de Configuración (002-modulo-configuracion), las de Seguridad (003-seguridad-bitacora), las de Empleados (004-modulo-empleados), las de Activos (005-modulo-activos), las de Inventario (006-modulo-inventario) y las de Entregas (007-modulo-entregas); el resto de módulos está **pendiente de diseño** (ver `docs/modulos.md` y sus preguntas abiertas). Cada diseño se documenta aquí ANTES de escribir las migraciones, y el usuario lo aprueba.

## Convenciones

- PostgreSQL 17. Claves primarias `id bigint` autoincremental (`$table->id()`).
- `snake_case` en plural para tablas; FK como `<entidad>_id` con `constrained()`.
- `timestamps()` en todas las tablas de negocio. Usar `softDeletes()` en entidades con historial que no debe perderse (activos, empleados, entregas).
- Una sola organización: **no** hay columna `organization_id`/tenant.
- Los movimientos de inventario y las entregas son registros históricos: no se editan ni se borran físicamente; se corrigen con un movimiento inverso.

## Tablas existentes

```sql
-- Autenticación (0001_01_01_000000_create_users_table
--   + 2026_10_17_200003_add_role_and_password_flags_to_users_table
--   + 2026_10_17_400000_add_lockout_columns_to_users_table
--   + 2026_10_17_500003_add_employee_id_to_users_table)
users(id, name, username, email NULL, email_verified_at NULL, password,
      role_id FK -> roles NOT NULL idx,                 -- un rol por usuario (RN-013)
      employee_id FK -> employees NULL UNIQUE RESTRICT, -- vínculo opcional uno a uno (RN-015, 004)
      is_active bool DEFAULT true,
      must_change_password bool DEFAULT false,          -- contraseña temporal (RN-014)
      last_login_at NULL,
      failed_login_attempts smallint DEFAULT 0,         -- RN-034: fallos seguidos (003)
      locked_until timestamptz NULL,                    -- RN-034: fin del bloqueo (003)
      remember_token, timestamps,
      UNIQUE(lower(username)), UNIQUE(lower(email)) WHERE email IS NOT NULL,   -- FR-008
      CHECK (position('@' in username) = 0))            -- '@' reservado para el login por correo
      -- username y email se guardan en minúsculas (mutators de User). No se borran:
      -- se desactivan. employee_id se gestiona desde Usuarios (RN-015, T006 de 004): empleado activo
      -- y libre; al editar se conserva el actual aunque esté inactivo; exige empleados.ver.
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
            min_stock integer NULL,                           -- RN-028 / D-008 de 005 (2026_10_17_600001)
            UNIQUE(asset_category_id, lower(name)),           -- asset_types_category_name_lower_unique
            CHECK asset_types_min_stock_check (min_stock IS NULL OR (min_stock >= 0 AND tracking_mode = 'quantity')))
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
- `asset_types.tracking_mode` no se puede cambiar si el tipo ya tiene activos (FR-009; `AssetType::hasAssets()` consulta `assets` desde 005). Al pasar un tipo sin activos a serializado, su `min_stock` se pone en null.
- Una categoría inactiva oculta sus tipos para registros nuevos (RN-003, scope `AssetType::available()`); no desactiva los tipos en cascada. Igual con tipos de ubicación inactivos y sus ubicaciones (`Location::available()`).
- Precarga (RN-012, `ConfigurationSeeder`): categorías + tipos solo si `asset_categories` está vacía; tipos de ubicación solo si `location_types` está vacía (D-008). No se precargan ubicaciones.
- La periodicidad de mantenimientos/vencimientos (RN-006) vive en `asset_maintenance_plans` (005).

RBAC: ver la sección "RBAC (feature 001-rbac)" en Tablas existentes.

## Seguridad (feature 003-seguridad-bitacora, migrado 2026-10-17)

```sql
-- users + failed_login_attempts, locked_until (ver Tablas existentes)
-- 2026_10_17_400001_create_security_events_table
security_events(id, event varchar(50) idx,      -- App\Enums\SecurityEventType: login_succeeded, login_failed, logout,
                                                -- account_locked, account_unlocked, user_created, user_updated,
                                                -- user_status_changed, password_reset, password_changed, role_created,
                                                -- role_updated, role_status_changed, role_permissions_updated,
                                                -- settings_updated (B-5 de 005: PUT /api/settings con
                                                --   metadata {changes:{expiry_alert_days:[antes, después]}})
                actor_id FK -> users NULL idx,  -- quién lo hizo; NULL en login_failed y account_locked (no autenticado)
                target_user_id FK -> users NULL idx, target_role_id FK -> roles NULL,
                login_attempted varchar(255) NULL,  -- texto ingresado, solo en login_failed (D-005); nunca la contraseña
                ip_address varchar(45) NULL, user_agent varchar(255) NULL,   -- recortado a 255
                metadata jsonb NULL,            -- p. ej. {changes:{campo:[antes,después]}}, {added,removed}, {reason}
                created_at timestamptz DEFAULT now() idx)   -- sin updated_at
-- SOLO INSERCIÓN (D-002): función prevent_security_events_change() + triggers
--   security_events_immutable_rows (BEFORE UPDATE OR DELETE, por fila) y
--   security_events_immutable_truncate (BEFORE TRUNCATE, por sentencia).
--   El modelo SecurityEvent también rechaza save() de existentes y delete().
```

Reglas asociadas:
- RN-034 / D-003: `RegisterFailedLogin` incrementa con un solo `UPDATE ... RETURNING` (sin tocar la fila mientras está bloqueada; bloqueo vencido → reinicia en 1). Umbral y duración en `config('auth.lockout')` (5 / 15 min). Un ingreso exitoso o el desbloqueo ponen el contador en 0.
- Las FK de `security_events` no tienen `ON DELETE`: los usuarios y roles no se borran; un `SET NULL` chocaría con el trigger.
- La conexión `pgsql` fija `timezone = UTC` (igual que `app.timezone`) para las columnas `timestamptz`. Los filtros por día de la bitácora se interpretan en America/Bogota (D-006).

## Empleados (feature 004-modulo-empleados, migrado 2026-10-17)

Sin `softDeletes`: empleados, cargos y compañías no se borran, se desactivan (RN-021, RN-003); FK `RESTRICT`.

```sql
-- 2026_10_17_500000_create_positions_table
positions(id, name varchar(120), description varchar(500) NULL, is_active bool DEFAULT true, timestamps,
          UNIQUE(lower(name)))                         -- positions_name_lower_unique; cargos/rangos (RN-035)
-- 2026_10_17_500001_create_companies_table
companies(id, name varchar(120), description varchar(500) NULL, is_active bool DEFAULT true, timestamps,
          UNIQUE(lower(name)))                         -- companies_name_lower_unique; compañías/turnos (RN-036)
-- 2026_10_17_500002_create_employees_table
employees(id, document_type varchar(5),                -- CHECK employees_document_type_check IN ('CC','CE','TI','PA','PPT')
          document_number varchar(20),                  -- solo [A-Z0-9] en mayúsculas (D-002)
          first_name varchar(80), last_name varchar(80),
          position_id FK -> positions RESTRICT NOT NULL idx, company_id FK -> companies NULL RESTRICT idx,
          phone varchar(30) NULL, email varchar(255) NULL, hire_date date NULL,
          shirt_size varchar(10) NULL, pants_size varchar(10) NULL, boot_size varchar(10) NULL, glove_size varchar(10) NULL,
          is_active bool DEFAULT true, timestamps,
          UNIQUE(document_type, document_number),       -- employees_document_type_number_unique (FR-002)
          INDEX(last_name, first_name, id))             -- orden del listado (FR-001)
-- 2026_10_17_500003_add_employee_id_to_users_table: users.employee_id (ver Tablas existentes)
-- 2026_10_17_500004_revoke_empleados_ver_from_consulta_role: migración de datos (RN-040, D-006)
```

Reglas asociadas:
- RN-022: los datos de `employees` solo se exponen con `empleados.ver`; `GET /api/employees/options` (empleados.ver ∨ entregas.ver ∨ reportes.ver) devuelve solo id, nombre completo y cargo (D-004).
- RN-035: `ConfigurationSeeder` precarga los 8 cargos solo si `positions` está vacía; `companies` no se precarga (RN-036).
- RN-040: Consulta se precarga sin `empleados.ver`; la migración 500004 se lo quita una vez a las instalaciones existentes.
- Ajuste al migrar: `companies.description` es `varchar(500)` como en los demás catálogos; índice compuesto para el orden del listado.

## Activos (feature 005-modulo-activos, migrado 2026-10-17)

Sin `softDeletes` (FR-001: los activos no se borran, se dan de baja). FK `RESTRICT`/sin `ON DELETE`.

```sql
-- 2026_10_17_600000_create_prevent_row_changes_function
-- prevent_row_changes(): función plpgsql reutilizable para tablas de SOLO INSERCIÓN
--   (RAISE 'La tabla % es de solo inserción (% rechazado).'). Cada tabla le conecta
--   <tabla>_immutable_rows (BEFORE UPDATE OR DELETE, por fila) y
--   <tabla>_immutable_truncate (BEFORE TRUNCATE, por sentencia). Reutilizarla en 006/007.
-- 2026_10_17_600001_add_min_stock_to_asset_types_table: asset_types.min_stock (ver Configuración)
-- 2026_10_17_600002_create_assets_table
assets(id, asset_type_id FK -> asset_types RESTRICT idx,   -- solo tipos 'serialized' (RN-007, validado en la app)
       plate varchar(50),                               -- RN-008 / D-006: recortada y en MAYÚSCULAS
       serial varchar(100) NULL, brand varchar(80) NULL, model varchar(80) NULL,
       acquisition_date date NULL, acquisition_value numeric(14,2) NULL, useful_life_months smallint NULL,
       expires_on date NULL idx,                        -- RN-031
       status varchar(20) DEFAULT 'available' idx,      -- RN-005 (App\Enums\AssetStatus)
       location_id FK -> locations NULL RESTRICT idx,   -- dónde está
       holder_employee_id FK -> employees NULL RESTRICT idx,  -- quién lo tiene (RN-039, lo fija Entregas)
       photo_path varchar(255) NULL,                    -- disco ASSETS_DISK: assets/{id}/{uuid}.jpg
       notes text NULL, timestamps,
       UNIQUE(lower(plate)),                            -- assets_plate_lower_unique
       CHECK assets_status_check (status IN ('available','assigned','maintenance','retired')),
       CHECK assets_holder_status_check ((status = 'assigned') = (holder_employee_id IS NOT NULL)),
       CHECK assets_acquisition_value_check (acquisition_value IS NULL OR acquisition_value >= 0),
       CHECK assets_useful_life_months_check (useful_life_months IS NULL OR useful_life_months > 0))
-- 2026_10_17_600003_create_asset_events_table — SOLO INSERCIÓN (triggers con prevent_row_changes())
asset_events(id, asset_id FK, event varchar(30),        -- App\Enums\AssetEventType: created, updated, status_changed,
                                                        -- location_changed, delivered, returned, maintenance_done, retired,
                                                        -- maintenance_plan_created, maintenance_plan_updated (A-1, revisión
                                                        -- de seguridad de 005: notes = nombre del plan; updated con changes)
             from_status varchar(20) NULL, to_status varchar(20) NULL,
             from_location_id FK -> locations NULL, to_location_id FK -> locations NULL,
             employee_id FK -> employees NULL idx,
             delivery_id FK -> deliveries NULL idx,     -- FK agregada en 007 (2026_10_17_800003)
             user_id FK -> users, notes text NULL,      -- notes: motivo de baja/cambio de estado, descripción del mantenimiento
             changes jsonb NULL,                        -- AJUSTE DE IMPLEMENTACIÓN: {campo:{from,to}} en 'updated' (D-002)
             created_at timestamptz DEFAULT now(),      -- sin updated_at
             INDEX(asset_id, created_at, id))
-- 2026_10_17_600004_create_asset_maintenance_plans_table
asset_maintenance_plans(id, asset_id FK idx, name varchar(120),       -- RN-029
                        interval_months smallint CHECK > 0, last_done_on date NULL,
                        next_due_on date idx,                          -- D-003: calculado al guardar (modelo)
                        alert_days smallint DEFAULT 30 CHECK >= 0,     -- RN-030
                        is_active bool DEFAULT true, timestamps)
-- 2026_10_17_600005_create_asset_maintenance_records_table — SOLO INSERCIÓN (triggers con prevent_row_changes())
asset_maintenance_records(id, asset_id FK, plan_id FK -> asset_maintenance_plans NULL idx,
                          performed_on date, description varchar(500), performed_by varchar(120) NULL,
                          cost numeric(14,2) NULL CHECK >= 0, user_id FK -> users,
                          created_at timestamptz DEFAULT now(),         -- sin updated_at
                          INDEX(asset_id, performed_on))
-- 2026_10_17_600006_create_app_settings_table
app_settings(key varchar(80) PK, value jsonb, updated_at)   -- expiry_alert_days (def. 30 en App\Models\AppSetting si no hay fila; RN-030)
```

Reglas asociadas:
- D-001: estado y poseedor solo cambian con las acciones de `App\Actions\Activos` (manual: `ChangeAssetStatus`/`ChangeAssetLocation`; Entregas: `AssignAsset`/`ReleaseAsset`), con `SELECT ... FOR UPDATE` sobre la fila. Asignado o Dado de baja → no cambian estado ni ubicación (409).
- D-003: `next_due_on = (last_done_on ?? fecha de creación en America/Bogota) + interval_months` sin desbordar el mes (`addMonthsNoOverflow`).
- D-004: "hoy" en America/Bogota; alertas excluyen activos `retired`.
- Ajustes al migrar (aprobados como ajustes de implementación): índices en `status`, `location_id`, `holder_employee_id`, `next_due_on`, `expires_on`, `asset_events(asset_id, created_at, id)`, `asset_events.employee_id/delivery_id`, `asset_maintenance_records(asset_id, performed_on)` y `plan_id`; CHECKs de estado/poseedor, valores ≥ 0 y periodicidad > 0; columna `asset_events.changes jsonb` (pedida por el contrato del frontend, D-002).

## Inventario (feature 006-modulo-inventario, migrado 2026-10-17)

Artículos por cantidad (RN-007). Sin `softDeletes`; FK `RESTRICT`/sin `ON DELETE`. Sin `created_at/updated_at` estándar (ver columnas).

```sql
-- 2026_10_17_700000_create_stock_levels_table
stock_levels(asset_type_id FK -> asset_types RESTRICT, location_id FK -> locations RESTRICT idx,
             quantity integer DEFAULT 0, updated_at timestamptz DEFAULT now(),
             PRIMARY KEY(asset_type_id, location_id),
             CHECK stock_levels_quantity_check (quantity >= 0))           -- FR-002, última defensa
-- 2026_10_17_700001_create_inventory_counts_table
inventory_counts(id, location_id FK -> locations RESTRICT idx,
                 status varchar(20) DEFAULT 'open',                       -- App\Enums\CountStatus
                 notes varchar(500) NULL,
                 opened_by FK -> users, closed_by FK -> users NULL,
                 opened_at timestamptz(6) DEFAULT now(), closed_at timestamptz(6) NULL,
                 CHECK inventory_counts_status_check (status IN ('open','closed')),
                 CHECK inventory_counts_closed_check ((status = 'closed') = (closed_at IS NOT NULL AND closed_by IS NOT NULL)),
                 UNIQUE INDEX inventory_counts_one_open_per_location (location_id) WHERE status = 'open')   -- D-004
-- 2026_10_17_700002_create_inventory_movements_table — SOLO INSERCIÓN (triggers con prevent_row_changes():
--   inventory_movements_immutable_rows / inventory_movements_immutable_truncate)
inventory_movements(id, type varchar(20),                -- App\Enums\MovementType
                    asset_type_id FK -> asset_types idx, quantity integer,
                    from_location_id FK -> locations NULL idx, to_location_id FK -> locations NULL idx,
                    delivery_id FK -> deliveries NULL idx,   -- FK agregada en 007 (2026_10_17_800003)
                    count_id FK -> inventory_counts NULL idx,   -- ajuste generado al cerrar un conteo (RN-027)
                    reason varchar(500) NULL, user_id FK -> users,
                    created_at timestamptz(6) DEFAULT now(),    -- sin updated_at
                    INDEX(created_at, id),
                    CHECK inventory_movements_type_check (type IN ('entry','exit','transfer','adjustment','delivery','return','loss')),
                    CHECK inventory_movements_quantity_check (quantity > 0),
                    CHECK inventory_movements_locations_check (   -- D-002
                      entry/return: solo to; exit/delivery: solo from; transfer: ambas y distintas;
                      adjustment: exactamente una; loss: ninguna))
-- 2026_10_17_700003_create_inventory_count_lines_table
inventory_count_lines(id, count_id FK -> inventory_counts,
                      asset_type_id FK -> asset_types NULL,   -- en serializados guarda el tipo del activo
                      asset_id FK -> assets NULL idx,
                      expected_quantity integer NULL, counted_quantity integer NULL, found bool NULL,
                      CHECK inventory_count_lines_kind_check (
                        (asset_id IS NULL AND asset_type_id IS NOT NULL AND expected_quantity IS NOT NULL AND found IS NULL)
                        OR (asset_id IS NOT NULL AND expected_quantity IS NULL AND counted_quantity IS NULL)),
                      CHECK inventory_count_lines_quantities_check (expected/counted NULL o >= 0),
                      UNIQUE INDEX inventory_count_lines_count_type_unique (count_id, asset_type_id) WHERE asset_id IS NULL,
                      UNIQUE INDEX inventory_count_lines_count_asset_unique (count_id, asset_id) WHERE asset_id IS NOT NULL)
```

Reglas asociadas:
- D-001: `stock_levels` e `inventory_movements` solo cambian con `App\Actions\Inventario\RecordStockMovement` (transacción, `INSERT ... ON CONFLICT DO NOTHING` + `SELECT ... FOR UPDATE` en orden `(asset_type_id, location_id)`). Quien registre varios movimientos en una transacción (007) debe llamarla en ese orden. SC-002: suma de movimientos = existencia.
- D-003: entradas y traslados solo con tipos disponibles (activo, categoría activa) hacia ubicaciones disponibles; sacar desde ubicaciones inactivas sí se permite. Motivo obligatorio en salidas y ajustes.
- D-004: al abrir un conteo se crean renglones de tipos por cantidad con existencia > 0 y de activos serializados en `available`/`maintenance` con esa ubicación. Al cerrar se recalcula lo esperado (con las existencias bloqueadas), se crean ajustes con `count_id` y los serializados sin marcar quedan `found = false` (RN-037: no cambian de estado).
- Ajustes al migrar (de implementación): CHECKs de ubicaciones por clase, de estado de cierre y de clase de renglón; índices únicos parciales de renglones; índices en FKs y `(created_at, id)`; `notes varchar(500)`; `created_at/opened_at/closed_at` con precisión de microsegundos (para `changed_since_open`, US3-4); `stock_levels.updated_at timestamptz`.

## Entregas (feature 007-modulo-entregas, migrado 2026-10-17)

Aprobado por el usuario (pregunta 17). Sin `softDeletes`: entregas y devoluciones son de solo inserción; FK `RESTRICT`/sin `ON DELETE`.

```sql
-- 2026_10_17_800000_create_deliveries_table — SOLO INSERCIÓN (triggers con prevent_row_changes():
--   deliveries_immutable_rows / deliveries_immutable_truncate)
-- SEQUENCE deliveries_code_seq (OWNED BY deliveries.code)          -- D-001
deliveries(id, code varchar(20) UNIQUE
               DEFAULT 'ENT-' || lpad(nextval('deliveries_code_seq')::text, 6, '0'),   -- consecutivo ENT-000001
           employee_id FK -> employees RESTRICT idx,
           kind varchar(20),                            -- App\Enums\DeliveryKind (RN-023)
           due_on date NULL,                            -- solo y siempre en préstamos
           from_location_id FK -> locations idx,        -- de dónde salen los artículos por cantidad
           signature_path varchar(255),                 -- RN-024 / D-002: signatures/{id}.png en ASSETS_DISK (privado)
           signature_sha256 char(64),                   -- M-3: agregada en 2026_10_17_800005 (ver abajo)
           notes text NULL,                             -- máx. 1000 (validado en la app)
           delivered_by FK -> users, delivered_at timestamptz,
           created_at timestamptz DEFAULT now(),        -- sin updated_at: no se edita
           INDEX(delivered_at, id),
           CHECK deliveries_kind_check (kind IN ('dotacion','prestamo')),
           CHECK deliveries_due_on_check ((kind = 'prestamo') = (due_on IS NOT NULL)))
-- 2026_10_17_800001_create_delivery_items_table — NO es de solo inserción: returned_quantity se acumula (D-004)
delivery_items(id, delivery_id FK idx, asset_id FK -> assets NULL idx,   -- serializado
               asset_type_id FK idx, quantity integer, returned_quantity integer DEFAULT 0,   -- sin timestamps
               CHECK delivery_items_quantity_check (quantity > 0),
               CHECK delivery_items_returned_quantity_check (returned_quantity BETWEEN 0 AND quantity),
               CHECK delivery_items_asset_quantity_check (asset_id IS NULL OR quantity = 1),
               UNIQUE INDEX delivery_items_delivery_asset_unique (delivery_id, asset_id) WHERE asset_id IS NOT NULL,
               UNIQUE INDEX delivery_items_delivery_type_unique (delivery_id, asset_type_id) WHERE asset_id IS NULL,
               UNIQUE INDEX delivery_items_pending_asset_unique (asset_id)
                 WHERE asset_id IS NOT NULL AND returned_quantity < quantity)   -- un activo, una sola entrega pendiente
-- 2026_10_17_800002_create_delivery_returns_table — SOLO INSERCIÓN (triggers con prevent_row_changes())
delivery_returns(id, delivery_item_id FK idx, quantity integer,
                 condition varchar(20),                 -- App\Enums\ReturnCondition (RN-026)
                 to_location_id FK -> locations NULL idx, notes text NULL,
                 received_by FK -> users, returned_at timestamptz, created_at timestamptz DEFAULT now(),
                 CHECK delivery_returns_quantity_check (quantity > 0),
                 CHECK delivery_returns_condition_check (condition IN ('good','damaged','lost')),
                 CHECK delivery_returns_location_check (good → con ubicación; lost → sin ubicación; damaged → libre:
                   serializado con ubicación, por cantidad sin ella, RN-038),
                 CHECK delivery_returns_lost_notes_check (condition <> 'lost' OR btrim(notes) <> ''))
-- 2026_10_17_800003_add_delivery_foreign_keys
--   inventory_movements.delivery_id y asset_events.delivery_id → FK deliveries
--   (inventory_movements_delivery_id_foreign, asset_events_delivery_id_foreign)
-- 2026_10_17_800004_guard_delivery_items — M-2 (revisión de seguridad de 007)
--   FUNCTION delivery_items_guard(): UPDATE solo si la fila sin returned_quantity queda igual
--     (to_jsonb(NEW) - 'returned_quantity' = to_jsonb(OLD) - 'returned_quantity') y
--     NEW.returned_quantity >= OLD.returned_quantity; DELETE rechazado (ERRCODE insufficient_privilege)
--   TRIGGER delivery_items_guard_rows BEFORE UPDATE OR DELETE FOR EACH ROW → delivery_items_guard()
--   TRIGGER delivery_items_immutable_truncate BEFORE TRUNCATE → prevent_row_changes()
-- 2026_10_17_800005_add_signature_sha256_to_deliveries_table — M-3 (revisión de seguridad de 007)
--   deliveries.signature_sha256 char(64) NOT NULL,      -- SHA-256 (hex minúsculas) del PNG de la firma
--     CHECK deliveries_signature_sha256_check (signature_sha256 ~ '^[0-9a-f]{64}$')
--   Relleno de filas previas: hash del archivo; si falta el archivo, 64 ceros solo en local
--   (en otro entorno la migración falla).
```

Reglas asociadas:
- D-001: el consecutivo lo asigna el DEFAULT de `code`; `App\Models\Delivery` lo lee tras insertar. `App\Actions\Entregas\CreateDelivery` reserva el `id` con `nextval(pg_get_serial_sequence('deliveries','id'))` para fijar `signature_path` sin actualizar la fila inmutable.
- D-002: la firma (data URL PNG ≤ 300 KB, ≤ 2000 px) se re-codifica con Intervention (`ProcessDeliverySignature`) y se escribe al final de la transacción; si algo falla se borra el archivo.
- D-003 / D-004: entregas y devoluciones solo con `CreateDelivery` / `RegisterDeliveryReturn`. Orden de bloqueo: ítems de la entrega (devoluciones) → activos por id (`AssignAsset`/`ReleaseAsset`) → existencias por `(asset_type_id, location_id)` (`RecordStockMovement`: delivery / return / loss, RN-038).
- D-005: estado derivado (sin columna): `closed` sin pendientes; `overdue` préstamo con pendientes y `due_on` < hoy en America/Bogota; si no, `open` (`Delivery::status()` y scope `withStatus`).
- Ajustes al migrar (de implementación): CHECKs de tipo/fecha esperada, cantidades, serializado = 1 unidad, ubicación y observación por estado de devolución; índices únicos parciales de ítems (incluido "un activo pendiente en una sola entrega"); índices en FKs y `(delivered_at, id)`; `created_at timestamptz` sin `updated_at`; `delivery_items` sin timestamps.
- Ajustes de la revisión de seguridad de 007 (pedidos por el hilo principal):
  - M-2: `delivery_items` solo admite aumentar `returned_quantity` (trigger `delivery_items_guard_rows`); sin DELETE ni TRUNCATE. Las limpiezas de pruebas con datos confirmados desactivan `delivery_items_guard_rows` dentro de su transacción (como `deliveries_immutable_rows`). Pendiente antes de datos reales: `GRANT UPDATE (returned_quantity)` por columna para el rol de la app.
  - M-3: `signature_sha256` se calcula con `hash('sha256', $png)` en `CreateDelivery` antes del insert; `Delivery::signatureIntact()` lo compara con el archivo.
  - B-1: `id`, `signature_path` y `signature_sha256` (Delivery) y `returned_quantity` (DeliveryItem) no son asignables en masa; `CreateDelivery` usa `forceCreate()` y las devoluciones `increment()`.

