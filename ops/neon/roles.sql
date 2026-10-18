-- Roles de PostgreSQL de producción (Neon) para ActivosB31.
--
-- CUÁNDO: una sola vez, DESPUÉS del primer Pre-Deploy de Render (cuando las
-- migraciones ya crearon las tablas) y ANTES de cambiar DB_URL de la app al
-- rol activosb31_app. Volver a ejecutarlo es seguro (idempotente) y es lo que
-- hay que hacer si una migración nueva agrega una tabla de solo inserción.
--
-- CÓMO: con psql 17, conectado por la conexión DIRECTA (sin -pooler) con el
-- rol dueño de las tablas (el que usa DB_MIGRATION_URL, p. ej. neondb_owner).
-- psql pide la contraseña del dueño; no va en la línea de comandos:
--
--   psql "postgresql://neondb_owner@<host-directo>/neondb?sslmode=require" \
--     -v ON_ERROR_STOP=1 -f ops/neon/roles.sql
--
-- CONTRASEÑAS (A2/A3 de la revisión del despliegue): este archivo crea los
-- roles SIN contraseña (todavía no pueden iniciar sesión) y nunca las toca.
-- Después, en una sesión interactiva de psql con la misma conexión:
--
--   psql "postgresql://neondb_owner@<host-directo>/neondb?sslmode=require"
--   neondb=> \password activosb31_app
--   neondb=> \password activosb31_backup
--
-- \password pide la contraseña sin mostrarla, calcula el hash SCRAM-SHA-256
-- en el cliente (password_encryption = scram-sha-256, el valor por defecto
-- de PostgreSQL 17 y de Neon) y envía solo el hash: la contraseña en claro no
-- pasa por argv, por variables de psql, por el historial (~/.psql_history)
-- ni por los logs del servidor. Genere cada una con `openssl rand -hex 32`
-- y guárdela en el gestor de contraseñas antes de pegarla (no en un archivo).
-- Para rotarla, repetir \password y actualizar la variable en Render.
--
-- Volver a ejecutar este archivo es seguro (idempotente): no recrea los
-- roles ni cambia sus contraseñas; solo reaplica parámetros, GRANT y REVOKE.
-- Es lo que hay que hacer si una migración nueva agrega una tabla de solo
-- inserción. Ver docs/despliegue.md, sección 4.
--
-- Roles:
--   activosb31_app    → la aplicación (DB_URL de Render, host -pooler). Solo
--                       DML; no crea ni altera tablas; no puede modificar ni
--                       borrar las tablas de solo inserción.
--   activosb31_backup → pg_dump del Cron Job de respaldo (variables PG* de
--                       ops/backup/backup.sh, conexión directa). Solo lectura.
--
-- PENDIENTE DE CONFIRMACIÓN DEL USUARIO: una migración condicional (que haga
-- los REVOKE de una tabla de solo inserción nueva si el rol activosb31_app
-- existe) para no depender de volver a ejecutar este archivo. NO está creada.
-- Mientras tanto: toda migración que cree una tabla de solo inserción debe
-- agregarse a la lista de abajo y este archivo debe ejecutarse de nuevo tras
-- el deploy.

\set ON_ERROR_STOP on

BEGIN;

-- 1. Roles (sin superusuario, sin crear bases ni roles) ---------------------

-- Sin PASSWORD: se fija aparte con \password (ver encabezado). Si el rol ya
-- existe no se toca su contraseña.
SELECT 'CREATE ROLE activosb31_app LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'activosb31_app')
\gexec

SELECT 'CREATE ROLE activosb31_backup LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'activosb31_backup')
\gexec

ALTER ROLE activosb31_app NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
ALTER ROLE activosb31_backup NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;

-- 2. Parámetros por rol -------------------------------------------------------
-- UTC igual que app.timezone y la conexión pgsql (las columnas timestamptz se
-- interpretan en UTC; los filtros por día se convierten a America/Bogota en
-- la app, D-006). statement_timeout de la app por debajo de
-- max_execution_time (60 s, php-production.ini); el respaldo tiene más margen.

ALTER ROLE activosb31_app SET timezone = 'UTC';
ALTER ROLE activosb31_app SET search_path = public;
ALTER ROLE activosb31_app SET statement_timeout = '30s';

ALTER ROLE activosb31_backup SET timezone = 'UTC';
ALTER ROLE activosb31_backup SET search_path = public;
ALTER ROLE activosb31_backup SET statement_timeout = '15min';

-- 3. Base de datos y esquema ------------------------------------------------

SELECT format('GRANT CONNECT ON DATABASE %I TO activosb31_app, activosb31_backup', current_database())
\gexec
SELECT format('REVOKE CREATE, TEMPORARY ON DATABASE %I FROM PUBLIC', current_database())
\gexec

REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO activosb31_app, activosb31_backup;

-- 4. Tablas y secuencias existentes ------------------------------------------

GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO activosb31_app;
REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM activosb31_app;
-- USAGE permite nextval() (ids, deliveries_code_seq, la reserva de id de
-- CreateDelivery); sin UPDATE no puede hacer setval().
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO activosb31_app;
-- Sin GRANT EXECUTE (A8): las únicas funciones del esquema son de trigger
-- (prevent_row_changes, prevent_security_events_change,
-- delivery_items_guard) y PostgreSQL no comprueba EXECUTE al dispararlas.

-- La tabla de control de migraciones es del rol dueño: la app solo la lee
-- (A8). Así una app comprometida no puede marcar migraciones como aplicadas
-- ni borrarlas para que se repitan.
REVOKE INSERT, UPDATE, DELETE ON migrations FROM activosb31_app;

GRANT SELECT ON ALL TABLES IN SCHEMA public TO activosb31_backup;
GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO activosb31_backup;

-- 5. Privilegios por defecto para tablas que creen migraciones futuras -------
-- Aplican a los objetos que cree el rol que ejecuta este archivo (el de
-- DB_MIGRATION_URL). Una tabla de solo inserción nueva recibe UPDATE/DELETE
-- por aquí: ver la nota "PENDIENTE" del encabezado.

ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO activosb31_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO activosb31_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON TABLES TO activosb31_backup;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT ON SEQUENCES TO activosb31_backup;

-- 6. Tablas de solo inserción (esquema-bd): segunda capa además de los
-- triggers prevent_row_changes() / prevent_security_events_change(). Si la app
-- se ve comprometida, ni siquiera puede intentar UPDATE/DELETE.

REVOKE UPDATE, DELETE, TRUNCATE ON
    security_events,             -- 003, D-002
    asset_events,                -- 005
    asset_maintenance_records,   -- 005
    inventory_movements,         -- 006
    deliveries,                  -- 007, RN-024
    delivery_returns             -- 007
FROM activosb31_app;

-- delivery_items (007, M-2): solo se acumula returned_quantity en las
-- devoluciones (D-004). UPDATE solo de esa columna; sin DELETE.
REVOKE UPDATE, DELETE, TRUNCATE ON delivery_items FROM activosb31_app;
GRANT UPDATE (returned_quantity) ON delivery_items TO activosb31_app;

COMMIT;

-- Verificación rápida (debe listar solo SELECT/INSERT en las tablas de solo
-- inserción y UPDATE por columna en delivery_items):
--
--   SELECT table_name, string_agg(privilege_type, ', ' ORDER BY privilege_type)
--   FROM information_schema.role_table_grants
--   WHERE grantee = 'activosb31_app'
--   GROUP BY table_name ORDER BY table_name;
--
--   SELECT column_name, privilege_type FROM information_schema.column_privileges
--   WHERE grantee = 'activosb31_app' AND table_name = 'delivery_items';
