# Implementation Plan: Módulo Entregas

**Branch**: `007-modulo-entregas` (sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

## Summary

Entregas inmutables (`deliveries`, `delivery_items`) con consecutivo `ENT-000001` (secuencia PostgreSQL), firma PNG obligatoria en almacenamiento privado (RN-024), varios artículos (RN-025), devoluciones parciales con estado (`delivery_returns`, RN-026, RN-038) y alertas de préstamos vencidos. Usa `AssignAsset`/`ReleaseAsset` (005) y `RecordStockMovement` (006). Frontend con `signature_pad 5.1.4` (verificado).

## Constitution Check

✅ Bloque 007 aprobado · ✅ RN-022..RN-026, RN-038, RN-039 · ✅ TDD · ⚠️ Firma y datos de empleados: revisión de `especialista-seguridad`.

## Decisiones

- **D-001 Consecutivo**: secuencia `deliveries_code_seq`; `code = 'ENT-' || lpad(nextval, 6, '0')` asignado en la inserción.
- **D-002 Firma**: el cliente envía `signature` como data URL `image/png` (≤ 300 KB decodificada, dimensiones ≤ 2000 px, se verifica la firma binaria PNG y se re-codifica con Intervention para descartar contenido no imagen). Se guarda en `signatures/{delivery_id}.png` en el disco `ASSETS_DISK`; se sirve con URL temporal.
- **D-003 Transacción**: crear entrega = validar todo (empleado activo, activos `available` y no en otra entrega abierta, tipos por cantidad con existencia en `from_location_id`), luego en una transacción: entrega, ítems, `AssignAsset` (estado `assigned`, `holder_employee_id`, ubicación se conserva — RN-039, evento `delivered`), `RecordStockMovement(delivery)`. Todo o nada.
- **D-004 Devolución** por ítem (`quantity ≤ pendiente`):
  - serializado `good` → `ReleaseAsset` a `available` en `to_location_id` (obligatoria); `damaged` → `maintenance` en `to_location_id`; `lost` → `retired` con `notes` obligatoria. Evento `returned`.
  - por cantidad `good` → `RecordStockMovement(return, to_location_id)`; `damaged`/`lost` → `RecordStockMovement(loss)` sin ubicación (RN-038).
  - `delivery_items.returned_quantity` se incrementa con bloqueo de fila.
- **D-005 Estado derivado**: `closed` si todos los ítems están devueltos; `overdue` si préstamo con pendientes y `due_on < hoy (America/Bogota)`; si no, `open`. Filtro por estado con subconsultas.
- **D-006 Empleado en Entregas**: `employee{id, full_name, position}` siempre; datos personales solo en el módulo Empleados (RN-022). La selección de empleado usa `/api/employees/options`.
- **D-007 Tenencias**: `GET /api/employees/{id}/holdings` (empleados.ver) lista ítems pendientes del empleado (para la ficha del empleado).

## Contrato (resumen)

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/deliveries` (`search` código/empleado, `employee_id, kind, status, from, to`, paginado desc) | entregas.ver |
| GET | `/api/deliveries/{id}` (ítems con pendientes, devoluciones, `signature_url`) | entregas.ver |
| POST | `/api/deliveries` `{employee_id, kind, due_on?, from_location_id, notes?, signature, items:[{asset_id}|{asset_type_id, quantity}]}` | entregas.gestionar |
| POST | `/api/deliveries/{id}/returns` `{items:[{delivery_item_id, quantity, condition, to_location_id?, notes?}]}` | entregas.gestionar |
| GET | `/api/deliveries/alerts` (préstamos vencidos) | entregas.ver |
| GET | `/api/employees/{id}/holdings` | empleados.ver |

## Frontend

`/entregas`: listado con filtros e insignias (Abierta / Cerrada / Vencida); asistente "Nueva entrega" (empleado → tipo y fecha → origen → artículos: buscar activos disponibles por placa y agregar cantidades con existencia visible → firma → confirmar); ficha con artículos, devoluciones y firma; diálogo "Registrar devolución". Componente `SignaturePad` (`'use client'`, conserva trazos al redimensionar). Ficha del empleado muestra tenencias.

## Orden

1. Migraciones + modelos + secuencia. 2. Crear entrega (tests de atomicidad y concurrencia). 3. Devoluciones. 4. Estado/alertas/tenencias. 5. Frontend + `SignaturePad`. 6. Seguridad, QA, navegador, commit.
