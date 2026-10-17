# API Contract: 001-rbac

Todas las rutas bajo `/api`, middleware `auth:sanctum` + `active` + `password.changed` (salvo donde se indica). Respuestas JSON en español. Errores: `401` sin sesión, `403` sin permiso, `422` validación (`{message, errors: {campo: [..]}}`), `409` conflicto de invariante (último admin, rol en uso).

## Sesión

### `GET /api/user` (sin `password.changed`)
```json
{ "user": {
    "id": 1, "name": "Administrador", "username": "admin", "email": "admin@activosb31.local",
    "role": { "id": 1, "code": "admin", "name": "Administrador" },
    "permissions": ["activos.ver", "activos.gestionar", "..."],
    "must_change_password": false } }
```
`permissions` = permisos efectivos (Administrador: los 13). `POST /api/login` devuelve el mismo `user`.

### `PUT /api/user/password` (sin `password.changed`) — FR-009, FR-010, FR-011
Body: `{ "current_password", "password", "password_confirmation" }` → `200 { "message" }`.
Pone `must_change_password=false`, cierra las demás sesiones y tokens del usuario.

### Bloqueo por cambio pendiente
Con `must_change_password=true`, cualquier ruta protegida por `password.changed` responde
`403 { "message": "Debes cambiar tu contraseña.", "code": "password_change_required" }`.

## Usuarios (`usuarios.ver` / `usuarios.gestionar`)

| Método | Ruta | Permiso | Body / query | Respuesta |
|---|---|---|---|---|
| GET | `/api/users` | ver | `search`, `role_id`, `is_active`, `page`, `per_page` (≤100) | paginado `{data: [UserResource], meta, links}` |
| GET | `/api/users/{user}` | ver | — | `{data: UserResource}` |
| POST | `/api/users` | gestionar | `name, username, email?, role_id, password, password_confirmation` | `201 {data}`; queda `must_change_password=true` |
| PATCH | `/api/users/{user}` | gestionar | `name, username, email?, role_id` | `{data}`; `409` si quita el último admin |
| PATCH | `/api/users/{user}/status` | gestionar | `is_active` | `{data}`; `409` si es el último admin o es uno mismo; al desactivar revoca sesiones y tokens |
| PUT | `/api/users/{user}/password` | gestionar | `password, password_confirmation` | `{message}`; `must_change_password=true`, revoca sesiones y tokens |

`UserResource`: `id, name, username, email, is_active, must_change_password, last_login_at, role{id,name,code}, created_at`.

## Roles (`usuarios.ver` / `usuarios.gestionar`)

| Método | Ruta | Permiso | Body / query | Respuesta |
|---|---|---|---|---|
| GET | `/api/roles` | ver | `search`, `is_active` | `{data: [RoleResource]}` (sin paginar; pocos registros) |
| POST | `/api/roles` | gestionar | `name, description?` | `201 {data}` sin permisos |
| PATCH | `/api/roles/{role}` | gestionar | `name, description?` | `{data}` |
| PATCH | `/api/roles/{role}/status` | gestionar | `is_active` | `{data}`; `409` si `is_system` o tiene usuarios activos |

`RoleResource`: `id, code, name, description, is_system, is_active, users_count, permissions[codes]`.

## Matriz de permisos (`usuarios.ver` / `usuarios.gestionar`)

### `GET /api/permissions` — ver
```json
{ "data": [ { "module": "activos", "label": "Activos",
              "permissions": [ { "code": "activos.ver", "action": "ver" },
                               { "code": "activos.gestionar", "action": "gestionar" } ] } ] }
```
La matriz se arma con este catálogo + `GET /api/roles` (cada rol trae sus `permissions`).

### `PUT /api/roles/{role}/permissions` — gestionar
Body: `{ "permissions": ["activos.ver", "activos.gestionar", ...] }` → `{data: RoleResource}`.
Reemplaza el conjunto del rol. El servidor normaliza: `gestionar` agrega `ver`. Códigos desconocidos → `422`. Rol `admin` → `403`.
