# Tasks: Módulo Inventario (006)

## Backend
- [x] T001 Migraciones del bloque 006 (+ índice único parcial de conteo abierto, trigger inmutable en movimientos); `esquema-bd` a "migrado"
- [x] T002 Modelos, enums (`MovementType`, `CountStatus`), factories
- [x] T003 Tests + `RecordStockMovement` (D-001, D-002): existencias nunca negativas, concurrencia, invariante suma de movimientos = existencia
- [x] T004 Tests + `GET/POST /api/inventory/movements`, `GET /api/inventory/stock`, `serialized-summary`, policies (`inventario.*`)
- [x] T005 Tests + conteos (D-004): abrir, renglones, cerrar con ajustes y no encontrados, uno abierto por ubicación
- [x] T006 Tests + `GET /api/inventory/alerts` (RN-028)

## Frontend
- [x] T007 `packages/app/features/inventario/{api,schemas}.ts` (+ tests)
- [x] T008 `/inventario`: pestañas Existencias, Movimientos (+ diálogo), Conteos (flujo completo), Serializados (+ tests)

## Cierre
- [ ] T009 Suites, typecheck, Pint · T010 Verificación en navegador y `CLAUDE.md`
