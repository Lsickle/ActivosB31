# Tasks: Módulo Activos (005)

**Tests**: obligatorios (TDD).

## Backend
- [x] T001 Instalar `intervention/image ^4.4`, `intervention/image-laravel ^4.1.1`, `league/flysystem-aws-s3-v3 ^3.35.3`; discos `local` privado y `r2` (config de `docs/infraestructura.md`) con `ASSETS_DISK`; `.env.example`
- [x] T002 Migraciones `assets`, `asset_events`, `asset_maintenance_plans`, `asset_maintenance_records`, `app_settings`, `asset_types.min_stock` + función/trigger `prevent_row_changes()`; bloque 005 de `esquema-bd` a "migrado"
- [x] T003 Enums `AssetStatus`, `AssetEventType`; modelos, factories; `AssetType::hasAssets()` real (activa FR-009 de 002, con test)
- [x] T004 Tests + CRUD de activos (D-006, D-007), `AssetResource`, policy (`activos.ver`/`activos.gestionar`), historial `created`/`updated`
- [x] T005 Tests + foto: re-codificación sin EXIF ≤1600 px, reemplazo borra la anterior, URL temporal, validación (tipo, 5 MB, dimensiones), 403
- [x] T006 Tests + estados, ubicación y baja (D-001), eventos; acciones `AssignAsset`/`ReleaseAsset` para 007 (con tests unitarios)
- [x] T007 Tests + planes y registros de mantenimiento (D-003), inmutabilidad
- [x] T008 Tests + alertas (D-004) y `app_settings` (D-005)
- [x] T009 Tests + `min_stock` en tipos de activo (D-008) y en `AssetTypeResource`/requests de 002

## Frontend
- [x] T010 `packages/app/features/activos/{api,schemas}.ts` (+ tests)
- [x] T011 `/activos`: listado, filtros, alertas; formulario alta/edición con foto
- [x] T012 `/activos/[id]`: ficha, acciones de estado/ubicación/baja, planes y registros de mantenimiento, historial
- [x] T013 Configuración: "Stock mínimo" en tipos por cantidad y ajuste "Anticipación de vencimientos"

## Cierre
- [ ] T014 Suites, typecheck, Pint (backend hecho: Pest 485 en verde y Pint; falta frontend)
- [ ] T015 Revisión de `especialista-seguridad` (subida de fotos) y correcciones
- [ ] T016 Verificación en navegador y `CLAUDE.md`
