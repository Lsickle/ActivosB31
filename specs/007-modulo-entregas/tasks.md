# Tasks: Módulo Entregas (007)

## Backend
- [x] T001 Migraciones del bloque 007 + secuencia `deliveries_code_seq` (D-001); `esquema-bd` a "migrado"
- [x] T002 Modelos, enums (`DeliveryKind`, `ReturnCondition`), factories
- [x] T003 Tests + validación y guardado de firma (D-002)
- [x] T004 Tests + `POST /api/deliveries` (D-003): atomicidad, activos no disponibles, existencia insuficiente, préstamo sin fecha, firma obligatoria, concurrencia sobre el mismo activo
- [x] T005 Tests + devoluciones (D-004) para cada estado y tipo de artículo, parciales, exceso
- [x] T006 Tests + listado/ficha/estado derivado (D-005), alertas, `holdings` (D-007), permisos (D-006)

## Frontend
- [x] T007 Instalar `signature_pad@5.1.4`; `SignaturePad` (+ test)
- [x] T008 `packages/app/features/entregas/{api,schemas}.ts` (+ tests)
- [x] T009 `/entregas`: listado, asistente de nueva entrega, ficha, devolución (+ tests); tenencias en la ficha del empleado

## Cierre
- [ ] T010 Suites, typecheck, Pint (backend: suite Pest y Pint en verde; falta typecheck/Vitest del frontend) · T011 `especialista-seguridad` · T012 Navegador y `CLAUDE.md`
