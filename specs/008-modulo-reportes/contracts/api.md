# API Contract: 008-modulo-reportes

Complementa `plan.md`. Fijado con el frontend ya construido (2026-10-17): tipos en `frontend/packages/app/features/reportes/api.ts`. Todas las rutas bajo `/api`, `auth:sanctum` + `active` + `password.changed`. Fechas `YYYY-MM-DD` en `America/Bogota`; marcas de tiempo ISO 8601.

## Reglas comunes

- Permiso: `reportes.ver` en todos los reportes (`403` sin él). `GET /api/dashboard` solo exige sesión.
- `format=json|xlsx|pdf` (por defecto `json`). El frontend envía siempre `format` como **último** parámetro y los mismos filtros en pantalla y descarga (D-001).
- `from`/`to` opcionales e **inclusivos** (RN-033). Validación: `date_format:Y-m-d`; `to >= from` → si no, `422` con el error en la clave **`to`**. Parámetros vacíos no se envían.
- IDs inexistentes (`employees/{id}`, `assets/{id}`, `asset-types/{id}`) → `404`.
- Errores siempre en JSON (el cliente envía `Accept: application/json` también al descargar): `422 {message, errors}`, `403 {message}`, `429`.

### Descargas (`format=xlsx|pdf`, D-002)

- `200` con el binario: `Content-Type` `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` o `application/pdf`; `Content-Disposition: attachment; filename="<nombre>"` (se acepta también `filename*=UTF-8''…`). Nombres: `inventario-YYYY-MM-DD.xlsx`, `reporte-empleado-YYYY-MM-DD.pdf`, `reporte-activo-YYYY-MM-DD.pdf`, `reporte-tipo-YYYY-MM-DD.xlsx` (el frontend usa estos mismos como respaldo).
- **CORS**: agregar `Content-Disposition` a `exposed_headers` en `config/cors.php` (hoy `[]`); sin eso, con el frontend en otro origen (dev), el navegador no deja leer el nombre y se usa el de respaldo. En producción (proxy de Vercel, mismo origen) no hace falta, pero no estorba.
- El archivo lleva el mismo encabezado que `ReportMeta` (fecha de generación, usuario, filtros) y las mismas filas y totales que el JSON.

### `ReportMeta` (en todos los reportes JSON)

```
generated_at: ISO 8601
generated_by: {id, name}
filters: [{label, value}]   // filtros aplicados, legibles y en el orden en que se imprimen en el archivo
                            // p. ej. [{label:"Ubicación", value:"Bodega principal"}, {label:"Desde", value:"2026-10-01"}]
```

## `GET /api/reports/inventory`

Query: `asset_category_id, asset_type_id, location_id, status (available|assigned|maintenance|retired), format`.
`status` **solo filtra los serializados**; la sección por cantidad no se ve afectada (la pantalla lo indica).

```
{data: {
  ...ReportMeta,
  serialized: [{asset_type:{id,name,category:{id,name}}, location:{id,name}|null, status, status_label, count}],
      // agrupado por tipo + ubicación + estado; location null = Asignados (RN-039) u otros sin ubicación
  quantity: [{asset_type:{id,name,category:{id,name}}, location:{id,name,is_active}, quantity, type_total, min_stock|null, below_min}],
      // igual que GET /api/inventory/stock (006): solo quantity > 0; type_total y below_min sobre todas las ubicaciones
  totals: {serialized, by_status:{available,assigned,maintenance,retired}, quantity_units, below_min_types}
}}
```

Orden: categoría, tipo, ubicación (nulls al final), estado. Sin paginar (el archivo trae lo mismo).

## `GET /api/reports/employees/{employee}`

Query: `from, to, format`. Incluye empleados inactivos (el selector del frontend usa `/api/employees/options`, que solo lista activos).

```
{data: {
  ...ReportMeta,
  employee: {id, full_name, position (nombre del cargo), is_active,
             // SOLO con empleados.ver (RN-022, D-003); sin el permiso estas claves NO vienen (ni como null):
             document_type, document_number, phone|null, email|null, company (nombre)|null},
  holdings: [{delivery:{id,code,kind,kind_label,due_on|null,status}, asset:{id,plate}|null,
              asset_type:{id,name,tracking_mode}, pending_quantity, delivered_at}],
      // lo que tiene HOY (pendiente > 0); no depende de from/to
  history: [{date, event:'delivery'|'return', event_label:'Entrega'|'Devolución',
             delivery:{id,code,kind,kind_label}, asset:{id,plate}|null, asset_type:{id,name},
             quantity, condition:'good'|'damaged'|'lost'|null, condition_label|null}],
      // una fila por delivery_item entregado y por devolución, dentro del rango; más recientes primero
  totals: {pending_units, delivered_units, returned_units}   // delivered/returned: del rango
}}
```

El Excel/PDF aplica la misma regla de RN-022 (sin documento ni contacto si falta `empleados.ver`).

## `GET /api/reports/assets/{asset}`

Query: `from, to, format`. Cualquier estado, incluido `retired`.

```
{data: {
  ...ReportMeta,
  asset: {id, plate, serial|null, brand|null, model|null, status, status_label,
          asset_type:{id,name,category:{id,name}}, location:{id,name}|null,
          holder:{id, full_name, position}|null, acquisition_date|null, expires_on|null, next_maintenance_on|null},
  events: [{id, event (AssetEventType de 005), event_label, detail|null, user:{id,name}|null, created_at}],
      // asset_events del rango (entregas, devoluciones, mantenimientos, cambios), más recientes primero.
      // `detail` es texto armado por el servidor (motivo, plan, ubicación origen → destino, código de entrega…),
      // el mismo que se imprime en el archivo.
  totals: {events}
}}
```

## `GET /api/reports/asset-options`

Query: `search` (placa o serial, sin distinguir mayúsculas). Permiso `reportes.ver`. Hasta **20** resultados, todos los estados, orden por placa. Sin `search` → `{data: []}`.

```
{data: [{id, plate, status, status_label, asset_type:{id,name}}]}
```

Existe para no exigir `activos.ver` al elegir el activo del reporte por artículo.

## `GET /api/reports/asset-types/{assetType}`

Query: `from, to, location_id, format`. Solo tipos `tracking_mode = quantity` (un tipo serializado → `404`; el frontend solo ofrece tipos por cantidad).

```
{data: {
  ...ReportMeta,
  asset_type: {id, name, category:{id,name}, min_stock|null},
  stock: [{location:{id,name,is_active}, quantity}],       // actual, quantity > 0; filtrado por location_id si viene
  movements: [{id, type, type_label, quantity, from_location:{id,name}|null, to_location:{id,name}|null,
               reason|null, delivery:{id,code}|null, user:{id,name}|null, created_at}],
      // inventory_movements del tipo en el rango (location_id = origen o destino), más recientes primero
  totals: {stock_total, below_min, movements}
      // stock_total = suma de `stock` mostrada; below_min se calcula sobre el total del tipo en TODAS las ubicaciones (RN-028)
}}
```

## `GET /api/dashboard` (D-004)

Solo sesión. Cada clave aparece **solo** si el usuario tiene el permiso; si no, la clave no viene.

```
{data: {
  assets?:     {by_status:{available,assigned,maintenance,retired}, total, alerts:{maintenance:[…], expirations:[…]}},
                 // activos.ver; `alerts` = misma forma que GET /api/assets/alerts (005 D-004)
  inventory?:  {below_min:[{asset_type:{id,name,category:{id,name}}, type_total, min_stock}]},
                 // inventario.ver; misma forma que GET /api/inventory/alerts (006)
  deliveries?: {open_count, overdue_count, overdue:[{id, code, employee:{id,full_name,position}, due_on, pending_quantity}]}
                 // entregas.ver; open_count = status `open`; overdue_count = status `overdue` (no se solapan);
                 // overdue = misma forma que GET /api/deliveries/alerts (007)
}}
```

El frontend enlaza a `/activos/{asset.id}`, `/inventario` y `/entregas/{id}`.

## Agregado por el hilo principal (2026-10-17)

- `GET /api/reports/employee-options?search` (`reportes.ver`) → hasta 20 resultados `[{id, full_name, position, is_active}]`, **incluye inactivos** (el reporte por empleado sirve para el historial de quien ya se retiró). Solo nombre y cargo (RN-022). El frontend debe usarlo en "Por empleado" en lugar de `employeesOptions()`.
- `GET /api/reports/asset-type-options?search` (`reportes.ver`) → tipos por cantidad, incluidos inactivos, `[{id, name, is_active, category:{id,name}}]`.
- CORS: `exposed_headers => ['Content-Disposition']` en `backend/config/cors.php`.
- Exportaciones: escapar celdas que empiecen por `=`, `+`, `-` o `@` (inyección de fórmulas, E-8 de la revisión de seguridad de 004).
