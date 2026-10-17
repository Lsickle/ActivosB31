# Implementation Plan: Módulo Inventario

**Branch**: `006-modulo-inventario` (sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

## Summary

Existencias `stock_levels` por tipo (por cantidad) y ubicación, movimientos inmutables `inventory_movements` (trigger `prevent_row_changes()` de 005), conteos físicos `inventory_counts` + `inventory_count_lines` (RN-027, RN-037) y alerta de stock mínimo (RN-028). Una acción `RecordStockMovement` centraliza toda variación de existencias (también la usan Entregas en 007). Sin dependencias nuevas.

## Constitution Check

✅ Esquema bloque 006 aprobado · ✅ RN-027, RN-028, RN-037, RN-038 · ✅ TDD · ➖ Sin datos personales ni auth: seguridad no obligatoria.

## Decisiones

- **D-001 Concurrencia**: `RecordStockMovement` en transacción: `INSERT ... ON CONFLICT DO NOTHING` de las filas de `stock_levels` involucradas y luego `SELECT ... FOR UPDATE` en orden (`asset_type_id, location_id`) para evitar interbloqueos; si una salida deja < 0 → 422 "Existencia insuficiente". El CHECK `>= 0` es la última defensa.
- **D-002 Tipos de movimiento**: `entry` (to), `exit` (from, motivo obligatorio), `transfer` (from + to distintos), `adjustment` (to = suma, from = resta; motivo obligatorio), `delivery` (from, desde 007), `return` (to, desde 007), `loss` (sin ubicación, desde 007, RN-038). Corrección = nuevo movimiento.
- **D-003 Validaciones**: solo tipos `quantity`; entradas y traslados solo a tipos activos de categoría activa y ubicaciones activas; salidas desde ubicaciones inactivas permitidas.
- **D-004 Conteo**: al abrir, renglones con lo esperado: tipos por cantidad con existencia > 0 en la ubicación y activos serializados con esa `location_id` en estado `available` o `maintenance` (los asignados están con el empleado, RN-039). Se pueden agregar renglones de tipos sin existencia. Un conteo abierto por ubicación (índice único parcial `WHERE status='open'`). Al cerrar: se recalcula lo esperado con la existencia actual (edge case 4; la respuesta indica `changed_since_open`), se crean ajustes por la diferencia con `count_id` y se devuelven los serializados no encontrados (sin cambiar su estado, RN-037). Renglones sin contar al cerrar → 422.
- **D-005 Serializados en existencias**: `GET /api/inventory/serialized-summary` agrupa `assets` por tipo, ubicación y estado.

## Contrato (resumen)

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/inventory/stock` (`asset_category_id, asset_type_id, location_id, below_min`, paginado) → filas tipo × ubicación + `type_total`, `min_stock`, `below_min` | inventario.ver |
| GET | `/api/inventory/serialized-summary` | inventario.ver |
| GET | `/api/inventory/movements` (`type, asset_type_id, location_id, from, to`, paginado desc) | inventario.ver |
| POST | `/api/inventory/movements` `{type: entry|exit|transfer|adjustment, asset_type_id, quantity, from_location_id?, to_location_id?, reason?}` | inventario.gestionar |
| GET/POST | `/api/inventory/counts` · `GET /api/inventory/counts/{id}` | ver / gestionar |
| PATCH | `/api/inventory/counts/{id}/lines` `{lines:[{id?, asset_type_id?, counted_quantity?, asset_id?, found?}]}` | gestionar |
| POST | `/api/inventory/counts/{id}/close` | gestionar |
| GET | `/api/inventory/alerts` (tipos bajo mínimo) | inventario.ver |

## Frontend

`/inventario` con pestañas: Existencias (filtros, insignia "Bajo mínimo"), Movimientos (historial con filtros + diálogo "Registrar movimiento"), Conteos (listado, abrir, registrar renglones, cerrar con resumen de ajustes y no encontrados), Serializados (resumen).

## Orden

1. Migraciones + modelos + `RecordStockMovement` (tests de concurrencia e invariante SC-002). 2. Movimientos y existencias. 3. Conteos. 4. Alertas. 5. Frontend. 6. QA, navegador, commit.
