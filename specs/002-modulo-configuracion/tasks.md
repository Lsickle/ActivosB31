# Tasks: Módulo Configuración (catálogos base)

**Input**: `specs/002-modulo-configuracion/` (plan.md, spec.md, research.md, data-model.md, contracts/api.md, quickstart.md)

**Tests**: obligatorios (TDD, constitución IV): cada tarea de test se escribe primero y debe fallar.

Rutas: backend en `backend/`, lógica compartida en `frontend/packages/app/`, UI web en `frontend/apps/next/`.

## Phase 1: Setup

- [x] T001 Actualizar `.claude/skills/esquema-bd/SKILL.md`: Configuración pasa a "migrado", con longitudes (120/500), FK `restrict` y nombres de índices de data-model.md

## Phase 2: Foundational (bloquea todas las historias)

- [x] T002 Test de esquema y modelos (tablas, CHECK de `tracking_mode`, índices únicos `lower()` incluida la unicidad por categoría, FK restrict, scopes `active()`/`available()`/`search()` sin mayúsculas ni tildes) en `backend/tests/Feature/Configuracion/CatalogModelsTest.php`
- [x] T003 Migraciones `backend/database/migrations/2026_10_17_300000_create_asset_categories_table.php`, `..._300001_create_asset_types_table.php`, `..._300002_create_location_types_table.php`, `..._300003_create_locations_table.php` (D-010)
- [x] T004 [P] Enum `backend/app/Enums/TrackingMode.php` con `label()` (RN-007)
- [x] T005 Modelos `backend/app/Models/{AssetCategory,AssetType,LocationType,Location}.php` y concern `backend/app/Models/Concerns/HasCatalogSearch.php` (D-003); `AssetType::hasAssets()` devuelve `false` (D-009)
- [x] T006 [P] Factories `backend/database/factories/{AssetCategory,AssetType,LocationType,Location}Factory.php` (con estado `inactive()`)
- [x] T007 Test de policies (ver/gestionar por permiso, Administrador, Consulta, rol sin permisos) en `backend/tests/Feature/Configuracion/CatalogPoliciesTest.php`
- [x] T008 `backend/app/Policies/ConfigurationCatalogPolicy.php` (abstracta) y subclases `{AssetCategory,AssetType,LocationType,Location}Policy.php` (D-001)
- [x] T009 Extender `backend/app/Rules/UniqueCaseInsensitive.php` con alcance opcional por columnas (D-007), con test en `backend/tests/Unit/` o `tests/Feature/Configuracion/UniqueCaseInsensitiveTest.php`
- [x] T010 Test del seeder (conteos 9/34/4/0, modos correctos, segunda ejecución no duplica, categoría renombrada no se recrea) en `backend/tests/Feature/Configuracion/ConfigurationSeederTest.php`
- [x] T011 `backend/database/seeders/ConfigurationSeeder.php` (solo si la tabla está vacía, D-008) y llamada desde `DatabaseSeeder.php` (RN-012, FR-014)
- [x] T012 [P] Tipos y cliente API `frontend/packages/app/features/configuracion/api.ts` y esquemas zod `schemas.ts` (+ `schemas.test.ts`) según contracts/api.md
- [x] T013 `frontend/apps/next/features/configuracion/ConfiguracionScreen.tsx` con pestañas y `useRequireAuth('configuracion.ver')`; reemplazar el placeholder en `frontend/apps/next/app/(dashboard)/configuracion/page.tsx` (+ test)

## Phase 3: User Story 1 — Categorías de activo (P1) 🎯 MVP

**Independent test**: crear, buscar, editar, desactivar y reactivar una categoría.

- [x] T014 [US1] Tests de endpoints `/api/asset-categories` (listado paginado, búsqueda sin tildes, filtro `is_active`, alta, duplicado sin mayúsculas → 422, edición, status sin cascada, 401/403, `DELETE` → 405, `asset_types_count`) en `backend/tests/Feature/Configuracion/AssetCategoriesTest.php`
- [x] T015 [US1] Requests `backend/app/Http/Requests/Configuracion/{ListAssetCategories,StoreAssetCategory,UpdateAssetCategory,UpdateCatalogStatus}Request.php` (normalización de nombre D-002)
- [x] T016 [US1] `AssetCategoryResource` y `AssetCategoryController` (con `TranslatesUniqueViolations`), rutas en `backend/routes/api.php`
- [x] T017 [US1] `CategoriesTab.tsx` + `CatalogFormDialog.tsx` (+ tests): búsqueda, filtro de estado, paginación, acciones ocultas sin `configuracion.gestionar`, confirmación al desactivar, errores 422 junto al campo

## Phase 4: User Story 2 — Tipos de activo (P1)

**Independent test**: con una categoría activa, crear tipos, filtrarlos, editarlos y desactivarlos.

- [x] T018 [US2] Tests de `/api/asset-types` (filtros `asset_category_id`/`tracking_mode`, unicidad por categoría, categoría inactiva rechazada al crear y al mover, conservar categoría inactiva al editar, `tracking_mode` obligatorio, FR-009 con doble de `hasAssets()`, `category.is_active` en el resource) en `backend/tests/Feature/Configuracion/AssetTypesTest.php`
- [x] T019 [US2] Requests, `AssetTypeResource`, `AssetTypeController` y rutas
- [x] T020 [US2] `AssetTypesTab.tsx` (+ test): columna Modo, insignia "Categoría inactiva", filtros, select solo de categorías activas (más la actual al editar)

## Phase 5: User Story 3 — Tipos de ubicación y ubicaciones (P2)

**Independent test**: crear un tipo de ubicación, ubicaciones de ese tipo, filtrarlas y desactivarlas.

- [x] T021 [P] [US3] Tests de `/api/location-types` en `backend/tests/Feature/Configuracion/LocationTypesTest.php`
- [x] T022 [P] [US3] Tests de `/api/locations` (filtro `location_type_id`, tipo inactivo rechazado, conservar el actual al editar, unicidad global) en `backend/tests/Feature/Configuracion/LocationsTest.php`
- [x] T023 [US3] Requests, Resources, `LocationTypeController`, `LocationController` y rutas
- [x] T024 [US3] `LocationTypesTab.tsx` y `LocationsTab.tsx` (+ tests), aviso "Primero crea un tipo de ubicación activo" (US3-1)

## Phase 6: Opciones disponibles (FR-010)

- [x] T025 Tests de `GET /api/configuracion/options` (excluye inactivos y los hijos de padres inactivos, 403 sin `configuracion.ver`) en `backend/tests/Feature/Configuracion/OptionsTest.php`
- [x] T026 `ConfigurationOptionsController` y ruta; el frontend usa este endpoint en los selects de T017–T024

## Phase 7: Polish

- [x] T027 `vendor/bin/pint --dirty`, `yarn typecheck`; `qa-test-runner` sobre todo el monorepo
- [x] T028 Verificación en navegador de quickstart.md (6 escenarios) y limpieza de datos de prueba
- [x] T029 Actualizar `CLAUDE.md` (estado actual) y `docs/modulos.md` si aplica; commit `feat(configuracion): …`

## Dependencies

- Phase 2 bloquea todo. US1 → US2 (tipos necesitan categorías). US3 independiente de US1/US2. Phase 6 después de US2 y US3.
- Backend y frontend de cada historia se pueden hacer en paralelo contra contracts/api.md.

## Parallel

- Backend (T002–T011, T014–T016, T018–T019, T021–T023, T025–T026) en un agente; frontend (T012–T013, T017, T020, T024) en otro, en paralelo.

## Implementation Strategy

MVP = Phase 2 + US1. Luego US2, US3 y opciones; verificación en navegador al final.
