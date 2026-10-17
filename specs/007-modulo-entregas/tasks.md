# Tasks: Módulo Entregas (007)

## Backend
- [ ] T001 Migraciones del bloque 007 + secuencia `deliveries_code_seq` (D-001); `esquema-bd` a "migrado"
- [ ] T002 Modelos, enums (`DeliveryKind`, `ReturnCondition`), factories
- [ ] T003 Tests + validación y guardado de firma (D-002)
- [ ] T004 Tests + `POST /api/deliveries` (D-003): atomicidad, activos no disponibles, existencia insuficiente, préstamo sin fecha, firma obligatoria, concurrencia sobre el mismo activo
- [ ] T005 Tests + devoluciones (D-004) para cada estado y tipo de artículo, parciales, exceso
- [ ] T006 Tests + listado/ficha/estado derivado (D-005), alertas, `holdings` (D-007), permisos (D-006)

## Frontend
- [ ] T007 Instalar `signature_pad@5.1.4`; `SignaturePad` (+ test)
- [ ] T008 `packages/app/features/entregas/{api,schemas}.ts` (+ tests)
- [ ] T009 `/entregas`: listado, asistente de nueva entrega, ficha, devolución (+ tests); tenencias en la ficha del empleado

## Cierre
- [ ] T010 Suites, typecheck, Pint · T011 `especialista-seguridad` · T012 Navegador y `CLAUDE.md`
