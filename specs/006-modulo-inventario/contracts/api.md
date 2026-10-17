# API Contract: 006-modulo-inventario

Complementa `plan.md`. Fijado con el frontend ya construido (2026-10-17). Todas las rutas bajo `/api`, `auth:sanctum` + `active` + `password.changed`.

## Existencias
`GET /api/inventory/stock?asset_category_id&asset_type_id&location_id&below_min=1&page&per_page(≤100)` →
`{data:[{asset_type:{id,name,category:{id,name}}, location:{id,name,is_active}, quantity, type_total, min_stock|null, below_min}], meta}`.
`type_total` y `below_min` se calculan sobre el total del tipo en **todas** las ubicaciones. Solo filas con `quantity > 0`.

`GET /api/inventory/serialized-summary` → `{data:[{asset_type:{id,name}, location:{id,name}|null, status, count}]}` (status de activos).

## Movimientos
`GET /api/inventory/movements?type&asset_type_id&location_id(origen o destino)&from&to(YYYY-MM-DD, inclusivos, America/Bogota)&page&per_page` →
items `{id, type, type_label, asset_type:{id,name}, quantity, from_location:{id,name}|null, to_location:{id,name}|null, reason, delivery_id|null, count_id|null, user:{id,name}|null, created_at}`, orden desc.

`POST /api/inventory/movements` `{type: entry|exit|transfer|adjustment, asset_type_id, quantity, from_location_id?, to_location_id?, reason|null}`:
- `entry`: `to_location_id`. `exit`: `from_location_id` + `reason`. `transfer`: ambos distintos.
- `adjustment`: **exactamente uno** de `to_location_id` (aumenta) o `from_location_id` (disminuye) + `reason`. No hay campo `direction`.
- Existencia insuficiente → `422` con el error en la clave **`quantity`** ("Existencia insuficiente.").
→ `201 {data: Movement}`.

## Conteos
`GET /api/inventory/counts?status&location_id&page&per_page` → items sin `lines`.
`POST /api/inventory/counts {location_id, notes?}` → `201 {data: InventoryCount}`; ya hay uno abierto → `422` en `location_id`.
`GET /api/inventory/counts/{id}` → `{data: InventoryCount}`.

`InventoryCount`: `{id, location:{id,name}, status, notes, opened_by:{id,name}|null, closed_by:{id,name}|null, opened_at, closed_at, changed_since_open?: bool, lines:[{id, asset_type:{id,name}|null, asset:{id,plate}|null, expected_quantity|null, counted_quantity|null, found|null}]}`. En renglones de serializados se incluye también `asset_type`.

`PATCH /api/inventory/counts/{id}/lines {lines:[...]}`:
- cantidad existente `{id, counted_quantity|null}`; serializado `{id, asset_id, found: bool}`; tipo nuevo `{asset_type_id, counted_quantity}` (se crea con `expected_quantity = 0`).
- Errores `lines.N.campo` (N = orden de envío). → `{data: InventoryCount}` completo. Conteo cerrado → `409`.

`POST /api/inventory/counts/{id}/close` → `{data: InventoryCount}` cerrado con `expected_quantity` recalculado y `changed_since_open`. Renglones de cantidad sin contar → `422`. Ajustes con `count_id`; serializados no encontrados no cambian de estado (RN-037).

## Alertas
`GET /api/inventory/alerts` → `{data:[{asset_type:{id,name,category:{id,name}}, type_total, min_stock}]}` (tipos bajo mínimo, RN-028).
