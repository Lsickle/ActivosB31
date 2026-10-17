---
name: esquema-bd
description: Esquema de la base de datos de ActivosB31 (PostgreSQL 17) en formato DDL compacto, con relaciones y trazabilidad a reglas de negocio. Úsalo antes de crear o modificar modelos, migraciones, repositorios o cualquier código que dependa de la estructura de tablas. No asumas la estructura de una tabla de memoria — consulta este skill primero, y actualízalo en el mismo cambio que agregue o modifique una migración.
---

# Esquema de base de datos — ActivosB31

> **Estado**: inicial. Solo existen las tablas base del esqueleto. El modelo de los módulos de negocio está **pendiente de diseño** (ver `docs/modulos.md` y sus preguntas abiertas). Cuando se diseñe, se documenta aquí ANTES de escribir las migraciones, y el usuario lo aprueba.

## Convenciones

- PostgreSQL 17. Claves primarias `id bigint` autoincremental (`$table->id()`).
- `snake_case` en plural para tablas; FK como `<entidad>_id` con `constrained()`.
- `timestamps()` en todas las tablas de negocio. Usar `softDeletes()` en entidades con historial que no debe perderse (activos, empleados, entregas).
- Una sola organización: **no** hay columna `organization_id`/tenant.
- Los movimientos de inventario y las entregas son registros históricos: no se editan ni se borran físicamente; se corrigen con un movimiento inverso.

## Tablas existentes

```sql
-- Autenticación (0001_01_01_000000_create_users_table)
users(id, name, username UNIQUE, email UNIQUE, email_verified_at NULL, password,
      is_active bool DEFAULT true, last_login_at NULL, remember_token, timestamps)
password_reset_tokens(email PK, token, created_at)
sessions(id PK, user_id NULL idx, ip_address, user_agent, payload, last_activity idx)

-- Sanctum
personal_access_tokens(id, tokenable_type, tokenable_id, name, token UNIQUE, abilities,
                       last_used_at, expires_at, timestamps)

-- Infraestructura Laravel
cache, cache_locks, jobs, job_batches, failed_jobs
```

## Tablas previstas (borrador, NO implementar sin aprobar)

Configuración (requisito explícito del usuario: tipos de activo dependen de categorías, 1:N):

```sql
asset_categories(id, name UNIQUE, description NULL, is_active, timestamps)
asset_types(id, asset_category_id FK -> asset_categories, name, description NULL, is_active, timestamps,
            UNIQUE(asset_category_id, name))
locations(id, name UNIQUE, description NULL, is_active, timestamps)
```

RBAC (mismo patrón que EcoLink-Platform, tablas propias sin paquete externo — pendiente de confirmar):

```sql
roles(id, code UNIQUE, name, description NULL, timestamps)
permissions(id, code UNIQUE, name, module, timestamps)   -- code: '<modulo>.<accion>'
role_permissions(role_id FK, permission_id FK, PK(role_id, permission_id))
user_roles(user_id FK, role_id FK, PK(user_id, role_id))
```

Activos, empleados, entregas e inventario: pendientes de las preguntas abiertas de `docs/modulos.md`.
