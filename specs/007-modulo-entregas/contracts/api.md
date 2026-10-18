# API Contract: 007-modulo-entregas

Complementa `plan.md`. Fijado con el frontend ya construido (2026-10-17).

## Recursos

`Delivery`: `{id, code, employee:{id,full_name,position}, kind:'dotacion'|'prestamo', kind_label, due_on|null, status:'open'|'closed'|'overdue', from_location:{id,name}, notes|null, delivered_by:{id,name}, delivered_at, signature_url (solo en show/store; URL temporal 10 min; **condicional**: solo si el usuario tiene `entregas.gestionar` o `empleados.ver` — `DeliveryPolicy::viewSignature`, M-1, provisional pregunta 33 / 33A de `docs/modulos.md`; sin esos permisos la clave no viene y la ficha se muestra sin la imagen), items:[{id, asset:{id,plate}|null, asset_type:{id,name,tracking_mode}, quantity, returned_quantity, pending_quantity, returns:[{id, quantity, condition, condition_label, to_location:{id,name}|null, notes, received_by:{id,name}, returned_at}]}]}`.

## Rutas

- `GET /api/deliveries?search(código o nombre del empleado)&employee_id&kind&status&from&to&page&per_page` (entregas.ver).
- `GET /api/deliveries/{id}` (entregas.ver).
- `POST /api/deliveries` (entregas.gestionar) `{employee_id, kind, due_on|null, from_location_id, notes|null (≤1000), signature: "data:image/png;base64,…" (≤300 KB), items:[{asset_id}|{asset_type_id, quantity}]}` → `201 {data: Delivery}`. En `dotacion`, `due_on` se ignora (se guarda null). Préstamo: `due_on` obligatorio y ≥ hoy (America/Bogota).
- `POST /api/deliveries/{id}/returns` (entregas.gestionar) `{items:[{delivery_item_id, quantity, condition:'good'|'damaged'|'lost', to_location_id?, notes?}]}` → `{data: Delivery}`.
  - `to_location_id` obligatorio: serializado `good` o `damaged`; por cantidad `good`. Por cantidad `damaged`/`lost` → pérdida sin ubicación (RN-038), no se exige.
  - `notes` obligatorio si `lost` (≤1000).
- `GET /api/deliveries/alerts` (entregas.ver) → `{data:[{id, code, employee:{id,full_name,position}, due_on, pending_quantity}]}` (préstamos vencidos con pendientes).
- `GET /api/employees/{id}/holdings` (empleados.ver) → `{data:[{delivery_item_id, delivery:{id,code,kind,kind_label,due_on,status,delivered_at}, asset:{id,plate}|null, asset_type:{id,name,tracking_mode}, quantity, pending_quantity}]}` (ítems con pendiente > 0).

## Errores 422

Claves: `employee_id`, `kind`, `due_on`, `from_location_id`, `notes`, `signature`, `items`, `items.N.asset_id`, `items.N.asset_type_id`, `items.N.quantity`. "Existencia insuficiente" y "activo no disponible" van en `items.N.*`. Devoluciones: `items.N.quantity|condition|to_location_id|notes` (N = índice del payload).

## Permisos cruzados

El asistente de entrega lee `GET /api/assets?status=available&search=` y `GET /api/inventory/stock?asset_type_id&location_id`. Esas dos lecturas se autorizan también con `entregas.gestionar` (además de `activos.ver` / `inventario.ver`).

### Recurso reducido de `GET /api/assets` (B-2, revisión de seguridad de 007)

Con `entregas.gestionar` **sin** `activos.ver`, `GET /api/assets` devuelve **solo los activos Disponibles** con un recurso reducido (`AssetForDeliveryResource`), solo con lo que necesita el asistente de entrega:

```
{data: [{id, plate, serial|null, status, asset_type:{id,name}, location:{id,name}|null}], meta: {…paginación}}
```

Sin marca, modelo, valor, observaciones, poseedor ni mantenimientos. Con `activos.ver` se recibe el recurso completo de 005. En el frontend: tipo `AssetForDelivery` (`frontend/packages/app/features/entregas/api.ts`).
