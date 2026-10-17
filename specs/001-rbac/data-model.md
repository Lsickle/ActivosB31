# Data Model: 001-rbac

Aprobado por el usuario (2026-10-17): rol único en `users.role_id` (reemplaza el pivote `user_roles` del borrador) y `users.must_change_password`. Se refleja en el skill `esquema-bd` en el mismo cambio que las migraciones.

## Tablas

```sql
roles(id, code varchar(50) NULL, name varchar(80), description varchar(500) NULL,
      is_system bool DEFAULT false,      -- precargado: no se desactiva (FR-013)
      is_active bool DEFAULT true, timestamps,
      UNIQUE(code), UNIQUE(lower(name)))
      -- code: 'admin' | 'almacenista' | 'consulta' para los precargados; NULL en roles creados por usuarios.

permissions(id, code varchar(80) UNIQUE,   -- '<modulo>.ver' | '<modulo>.gestionar' (RN-009)
            module varchar(40), action varchar(20) CHECK IN ('ver','gestionar'),
            name varchar(120), timestamps)
            -- catálogo fijo, sincronizado por seeder desde App\Enums\Permission; no hay CRUD.

role_permissions(role_id FK -> roles ON DELETE CASCADE,
                 permission_id FK -> permissions ON DELETE CASCADE,
                 PRIMARY KEY(role_id, permission_id))

-- Cambios a users (migración nueva, no se edita la original):
users + role_id FK -> roles (NOT NULL tras asignar 'admin' al usuario existente), index
      + must_change_password bool DEFAULT false              -- RN-014
      ~ email pasa a NULL (correo opcional)
      - UNIQUE(username), UNIQUE(email)  → UNIQUE(lower(username)), UNIQUE(lower(email)) WHERE email IS NOT NULL
      + CHECK (position('@' in username) = 0)
```

Tabla `user_roles` del borrador: **no se crea** (RN-013).

## Catálogo de permisos (`App\Enums\Permission`)

| Módulo (`module`) | ver | gestionar |
|---|---|---|
| activos | activos.ver | activos.gestionar |
| inventario | inventario.ver | inventario.gestionar |
| entregas | entregas.ver | entregas.gestionar |
| empleados | empleados.ver | empleados.gestionar |
| reportes | reportes.ver | — |
| configuracion | configuracion.ver | configuracion.gestionar |
| usuarios | usuarios.ver | usuarios.gestionar |

13 permisos. "usuarios" cubre usuarios, roles y la matriz.

## Roles precargados (FR-003, RN-010)

| code | name | Permisos iniciales |
|---|---|---|
| admin | Administrador | Todos (implícito: `Gate::before` concede todo; además se sincronizan las 13 filas para que la matriz lo muestre) |
| almacenista | Almacenista | activos/inventario/entregas: ver + gestionar; empleados, reportes, configuracion, usuarios: ver |
| consulta | Consulta | los 7 `*.ver` |

El seeder es idempotente: crea roles/permisos que falten y asigna permisos iniciales **solo al crear el rol** (no pisa cambios hechos desde la matriz), excepto Administrador, que siempre se re-sincroniza con todos.

## Reglas de validación

- `roles.name`: requerido, ≤ 80, único sin mayúsculas. `description` ≤ 500.
- `users.name` ≤ 120; `username` 3–40, `[a-z0-9._-]`, sin `@`, se guarda en minúsculas; `email` opcional, formato válido, minúsculas, único.
- Contraseña (FR-010): ≥ 10 caracteres, letras y números (`Password::min(10)->letters()->numbers()`); en cambio propio, distinta de la actual.
- `role_id` debe apuntar a un rol activo al crear/editar.

## Invariantes y transiciones

- **Al menos un Administrador activo** (FR-012): no se puede desactivar, ni cambiar el rol, del último usuario activo con rol `admin`. Se verifica dentro de una transacción con bloqueo (`lockForUpdate`) sobre los usuarios admin activos.
- Un usuario no puede desactivarse a sí mismo.
- Usuario: `activo ⇄ inactivo`. Al pasar a inactivo, al restablecer contraseña o al cambiarla: se borran sus filas en `sessions` (salvo la sesión actual en cambio propio) y sus `personal_access_tokens` (FR-011).
- `must_change_password`: `true` al crear o restablecer por el admin → `false` al cambiarla el propio usuario.
- Rol: `activo ⇄ inactivo`; `is_system` no se desactiva; un rol con usuarios activos no se desactiva.
- Matriz: al marcar `gestionar` se agrega `ver`; al quitar `ver` se quita `gestionar` (normalizado en el servidor). El rol `admin` no se edita.
