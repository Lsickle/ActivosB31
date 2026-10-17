# API Contract: 002-modulo-configuracion

Todas las rutas bajo `/api`, middleware `auth:sanctum` + `active` + `password.changed`. Respuestas JSON en español. Errores: `401` sin sesión, `403` sin permiso (o `code: password_change_required`), `404` inexistente, `422` validación (`{message, errors: {campo: [..]}}`), incluida la unicidad en carrera.

Permisos: **ver** = `configuracion.ver`; **gestionar** = `configuracion.gestionar` (RN-011). No existe `DELETE` en ninguna ruta (RN-003): responde `405`.

## Patrón común por catálogo

| Método | Ruta | Permiso | Body / query | Respuesta |
|---|---|---|---|---|
| GET | `/api/<catalogo>` | ver | `search`, `is_active`, `page`, `per_page` (≤100, def. 25) + filtros propios | paginado `{data, meta, links}`, orden por `name` |
| GET | `/api/<catalogo>/{id}` | ver | — | `{data}` |
| POST | `/api/<catalogo>` | gestionar | campos del catálogo | `201 {data}`, activo |
| PATCH | `/api/<catalogo>/{id}` | gestionar | campos del catálogo | `{data}` |
| PATCH | `/api/<catalogo>/{id}/status` | gestionar | `{is_active: bool}` | `{data}`; sin cascada |

## Catálogos

### Categorías — `/api/asset-categories`
- Body: `name`, `description?`
- Resource: `id, name, description, is_active, asset_types_count, created_at, updated_at`

### Tipos de activo — `/api/asset-types`
- Filtros extra: `asset_category_id`, `tracking_mode`
- Body: `asset_category_id` (activa; al editar puede conservar la actual), `name` (único por categoría), `description?`, `tracking_mode` (`serialized`|`quantity`)
- `422` en `tracking_mode` si el tipo ya tiene activos (FR-009, sin efecto hasta que exista Activos)
- Resource: `id, name, description, tracking_mode, tracking_mode_label, is_active, category{id, name, is_active}, created_at, updated_at`

### Tipos de ubicación — `/api/location-types`
- Body: `name`, `description?`
- Resource: `id, name, description, is_active, locations_count, created_at, updated_at`

### Ubicaciones — `/api/locations`
- Filtro extra: `location_type_id`
- Body: `location_type_id` (activo; al editar puede conservar el actual), `name`, `description?`
- Resource: `id, name, description, is_active, location_type{id, name, is_active}, created_at, updated_at`

## Opciones disponibles — FR-010 (D-005)

### `GET /api/configuracion/options` — ver
Solo registros disponibles, sin paginar, ordenados por nombre:
```json
{ "data": {
    "asset_categories": [ { "id": 1, "name": "Comunicaciones" } ],
    "asset_types": [ { "id": 3, "name": "Radio portátil", "asset_category_id": 1, "tracking_mode": "serialized" } ],
    "location_types": [ { "id": 1, "name": "Bodega" } ],
    "locations": [ { "id": 7, "name": "Bodega principal", "location_type_id": 1 } ] } }
```
- `asset_types`: activos **y** de categoría activa.
- `locations`: activas **y** de tipo de ubicación activo.
