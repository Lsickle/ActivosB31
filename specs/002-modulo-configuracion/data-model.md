# Data Model: 002-modulo-configuracion

Fuente: `esquema-bd` (aprobado 2026-10-17). Se precisan longitudes (spec, Assumptions) y FK `restrict` (D-010). Ningún catálogo usa `softDeletes`: no se borran, se desactivan (RN-003).

```sql
asset_categories(
  id bigint PK,
  name varchar(120) NOT NULL,
  description varchar(500) NULL,
  is_active bool NOT NULL DEFAULT true,
  timestamps,
  UNIQUE(lower(name))                          -- asset_categories_name_lower_unique
)

asset_types(
  id bigint PK,
  asset_category_id bigint NOT NULL FK -> asset_categories RESTRICT, idx,
  name varchar(120) NOT NULL,
  description varchar(500) NULL,
  tracking_mode varchar(20) NOT NULL CHECK (tracking_mode IN ('serialized','quantity')),  -- RN-007
  is_active bool NOT NULL DEFAULT true,
  timestamps,
  UNIQUE(asset_category_id, lower(name))       -- asset_types_category_name_lower_unique
)

location_types(
  id bigint PK,
  name varchar(120) NOT NULL,
  description varchar(500) NULL,
  is_active bool NOT NULL DEFAULT true,
  timestamps,
  UNIQUE(lower(name))                          -- location_types_name_lower_unique
)

locations(
  id bigint PK,
  location_type_id bigint NOT NULL FK -> location_types RESTRICT, idx,
  name varchar(120) NOT NULL,
  description varchar(500) NULL,
  is_active bool NOT NULL DEFAULT true,
  timestamps,
  UNIQUE(lower(name))                          -- locations_name_lower_unique   (RN-002: sin parent_id)
)
```

## Modelos

| Modelo | Relaciones | Scopes / métodos |
|---|---|---|
| `AssetCategory` | `hasMany(AssetType)` | `active()`, `search()` |
| `AssetType` | `belongsTo(AssetCategory)` | `active()`, `available()` = activo y categoría activa (FR-008, FR-010), `search()`, `hasAssets()` (D-009); cast `tracking_mode` → `App\Enums\TrackingMode` |
| `LocationType` | `hasMany(Location)` | `active()`, `search()` |
| `Location` | `belongsTo(LocationType)` | `active()`, `available()` = activa y tipo activo, `search()` |

`App\Enums\TrackingMode`: `Serialized = 'serialized'` ("Serializado"), `Quantity = 'quantity'` ("Por cantidad"), con `label()`.

## Validación

| Campo | Reglas |
|---|---|
| `name` | requerido, string, recortado y con espacios internos colapsados (D-002), 1–120, único sin mayúsculas (global; por categoría en tipos de activo) — FR-006 |
| `description` | opcional, string, ≤ 500; cadena vacía → `null` |
| `asset_category_id` | requerido, existe, **activa** (FR-007). Al editar se admite conservar la categoría actual aunque esté inactiva (edge case). |
| `tracking_mode` | requerido, `serialized`\|`quantity`; no cambia si `hasAssets()` (FR-009) |
| `location_type_id` | requerido, existe, **activo**; al editar se admite conservar el actual aunque esté inactivo |
| `is_active` | requerido, boolean (solo en `/status`) |

## Estados

`is_active`: `true ⇄ false` mediante `PATCH …/status`. Sin cascada (FR-008). Un registro inactivo:
- sigue en los listados (filtro `is_active`) — SC-003;
- no aparece en opciones disponibles ni en selects de formularios — FR-007, FR-010;
- sus hijos conservan su propio estado, pero quedan fuera de `available()`.

## Datos iniciales (RN-012)

Ver tabla "Datos iniciales" de [spec.md](spec.md): 9 categorías, 34 tipos de activo, 4 tipos de ubicación; sin ubicaciones. Carga por `ConfigurationSeeder` solo si la tabla destino está vacía (D-008). Descripciones vacías.
