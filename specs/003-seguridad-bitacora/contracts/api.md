# API Contract: 003-seguridad-bitacora

Rutas bajo `/api`, `auth:sanctum` + `active` + `password.changed` salvo el login.

## `POST /api/login` (cambios)
- Cuenta bloqueada (`locked_until > now()`): `422 {message, errors: {login: ["Credenciales inválidas."]}}` — idéntico a contraseña errada.
- Cada fallo contra una cuenta existente incrementa su contador; el 5.º seguido la bloquea 15 min.
- Éxito: contador a 0.

## `POST /api/users/{user}/unlock` — `usuarios.gestionar`
→ `200 {data: UserResource}`. Sin efecto (y sin evento) si no estaba bloqueada.

## `UserResource` (cambio)
+ `locked_until: string|null` (ISO 8601; `null` si no está bloqueada o el bloqueo ya venció).

## `GET /api/security-events` — `usuarios.gestionar`
Query: `event` (código), `user_id` (actor o afectado), `from`, `to` (YYYY-MM-DD, inclusivas), `page`, `per_page` (≤100, def. 25).
```json
{ "data": [ {
    "id": 10, "event": "role_permissions_updated", "event_label": "Matriz de permisos modificada",
    "created_at": "2026-10-17T15:04:05-05:00",
    "actor": { "id": 1, "name": "Administrador", "username": "admin" },
    "target_user": null,
    "target_role": { "id": 2, "name": "Almacenista" },
    "login_attempted": null,
    "ip_address": "190.0.0.1", "user_agent": "Mozilla/5.0 …",
    "metadata": { "added": ["reportes.ver"], "removed": [] } } ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 } }
```

## `GET /api/security-events/types` — `usuarios.gestionar`
`{data: [{code, label}]}` para el filtro.

## Eventos y `metadata`

| code | label | target | metadata |
|---|---|---|---|
| `login_succeeded` | Ingreso exitoso | usuario | `{channel: web|token}` |
| `login_failed` | Ingreso fallido | usuario o null | `{reason: invalid|locked|inactive}` + `login_attempted` |
| `logout` | Cierre de sesión | usuario | — |
| `account_locked` | Cuenta bloqueada | usuario | `{until}` |
| `account_unlocked` | Cuenta desbloqueada | usuario | — |
| `user_created` | Usuario creado | usuario | `{role}` |
| `user_updated` | Usuario editado | usuario | `{changes: {campo: [antes, después]}}` (sin contraseña) |
| `user_status_changed` | Usuario activado/desactivado | usuario | `{is_active}` |
| `password_reset` | Contraseña restablecida | usuario | — |
| `password_changed` | Contraseña cambiada | usuario (= actor) | — |
| `role_created` / `role_updated` / `role_status_changed` | Rol … | rol | cambios / `{is_active}` |
| `role_permissions_updated` | Matriz de permisos modificada | rol | `{added, removed}` |
