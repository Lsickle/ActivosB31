# Implementation Plan: Módulo Configuración (catálogos base)

**Branch**: `002-modulo-configuracion` (trabajo sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/002-modulo-configuracion/spec.md`

## Summary

Cuatro catálogos administrables: categorías de activo, tipos de activo (1:N con categoría, modo de control serializado o por cantidad, RN-007), tipos de ubicación (RN-004) y ubicaciones planas (RN-002). Se crean, listan con búsqueda y paginación, editan, desactivan y reactivan; nunca se borran (RN-003). Cualquier rol con `configuracion.ver` consulta y solo `configuracion.gestionar` modifica (RN-009, RN-011). Precarga idempotente de los datos iniciales confirmados (RN-012). Backend: cuatro tablas, modelos, Policies, Form Requests y controladores con el mismo patrón de 001-rbac (Roles). Frontend: `/configuracion` con cuatro pestañas, `DataTable`, diálogos de formulario y confirmación, acciones ocultas sin `gestionar`.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13); TypeScript (Next.js 16, React 19)

**Primary Dependencies**: Sanctum 4; shadcn/ui (Base UI), TanStack Table, zod, sonner — ya instaladas. **Sin dependencias nuevas** (búsqueda sin tildes con `translate()` de PostgreSQL, sin extensión `unaccent`, D-003).

**Storage**: PostgreSQL 17: `asset_categories`, `asset_types`, `location_types`, `locations` (esquema aprobado en `esquema-bd`).

**Testing**: Pest 4 (backend, en paralelo); Vitest + Testing Library (frontend).

**Target Platform**: API en Render (Docker), web en Vercel.

**Project Type**: Aplicación web (monorepo `backend/` + `frontend/`).

**Performance Goals**: búsqueda sobre 500 registros < 1 s percibido (SC-004): consultas paginadas, índices en FK y `lower(name)`.

**Constraints**: toda escritura protegida en el servidor (FR-011); unicidad garantizada por índices `lower()` además de la validación (carrera → 422); el seeder corre en cada arranque de Render y no debe pisar ediciones del administrador (D-008).

**Scale/Scope**: decenas a cientos de registros por catálogo; 9 categorías, 34 tipos y 4 tipos de ubicación precargados.

## Constitution Check

| Principio | Estado |
|---|---|
| I. Esquema como fuente de verdad | ✅ Tablas aprobadas en `esquema-bd` (2026-10-17). Se precisan longitudes (120/500, de la spec) y FK `restrict`; `esquema-bd` pasa a "migrado" en el mismo cambio que las migraciones. |
| II. Trazabilidad | ✅ RN-002, RN-003, RN-004, RN-007, RN-011, RN-012 en comentarios, nombres de test y commits. |
| III. No reinterpretación | ✅ Sin preguntas abiertas. Decisiones técnicas sin impacto de negocio documentadas en `research.md`; la de opciones para otros módulos (D-005) queda señalada para revisarse con Activos. |
| IV. TDD | ✅ Cada tarea empieza con tests Pest/Vitest en rojo. |
| V. Revisión de seguridad | ➖ No toca autenticación, tokens ni datos personales. Reutiliza el RBAC ya revisado; la autorización se cubre con tests 403 por endpoint. No es obligatoria. |

Gate: **PASA**. Re-evaluado tras Fase 1: sin cambios.

## Project Structure

### Documentation (this feature)

```text
specs/002-modulo-configuracion/
├── plan.md
├── research.md          # D-001 … D-010
├── data-model.md
├── quickstart.md
├── contracts/api.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Models/{AssetCategory,AssetType,LocationType,Location}.php   # scopes active()/available(), search()
│   ├── Enums/TrackingMode.php                                       # serialized | quantity (RN-007)
│   ├── Models/Concerns/HasCatalogSearch.php                         # búsqueda sin mayúsculas ni tildes (D-003)
│   ├── Policies/ConfigurationCatalogPolicy.php                      # base: configuracion.ver / .gestionar (D-001)
│   ├── Policies/{AssetCategory,AssetType,LocationType,Location}Policy.php  # subclases para autodiscovery
│   ├── Rules/UniqueCaseInsensitive.php                              # + alcance opcional (asset_category_id) (D-007)
│   ├── Http/Controllers/Api/{AssetCategory,AssetType,LocationType,Location}Controller.php
│   ├── Http/Requests/Configuracion/{List,Store,Update,UpdateStatus}<Catalogo>Request.php
│   └── Http/Resources/{AssetCategory,AssetType,LocationType,Location}Resource.php
├── database/
│   ├── migrations/2026_10_17_3000xx_create_{asset_categories,asset_types,location_types,locations}_table.php
│   ├── seeders/ConfigurationSeeder.php                              # RN-012, solo si la tabla está vacía (D-008)
│   └── factories/{AssetCategory,AssetType,LocationType,Location}Factory.php
├── routes/api.php                                                   # dentro de password.changed
└── tests/Feature/Configuracion/{AssetCategories,AssetTypes,LocationTypes,Locations,Options,Seeder}Test.php

frontend/
├── packages/app/features/configuracion/{api,schemas}.ts (+ schemas.test.ts)
└── apps/next/
    ├── app/(dashboard)/configuracion/page.tsx                       # reemplaza el placeholder
    └── features/configuracion/
        ├── ConfiguracionScreen.tsx                                  # pestañas, useRequireAuth('configuracion.ver')
        ├── {Categories,AssetTypes,LocationTypes,Locations}Tab.tsx (+ .test.tsx)
        ├── CatalogFormDialog.tsx                                    # nombre + descripción (+ campos extra por catálogo)
        └── StatusToggle / filtros compartidos según se repitan
```

**Structure Decision**: monorepo existente; mismos alias y patrones que `features/usuarios` (pestañas como `UsuariosScreen`, `DataTable`, `ConfirmDialog`, `FormField`, `applyFieldErrors`).

## Orden de implementación

1. Migraciones + enum `TrackingMode` + modelos + factories + `esquema-bd` (tests de modelo: scopes `available()`, unicidad en BD).
2. `ConfigurationSeeder` (idempotente, respeta ediciones) y registro en `DatabaseSeeder`.
3. Policies + endpoints de categorías (patrón base, con tests 401/403/422/409-no aplica).
4. Endpoints de tipos de activo (filtros, categoría activa, unicidad por categoría, FR-009 preparado).
5. Endpoints de tipos de ubicación y ubicaciones.
6. Endpoints de opciones disponibles (FR-010).
7. Frontend: `api.ts`/`schemas.ts`, `ConfiguracionScreen` y pestaña Categorías.
8. Frontend: pestañas Tipos de activo, Tipos de ubicación y Ubicaciones.
9. `qa-test-runner`, verificación en navegador (quickstart), actualización de `CLAUDE.md` y `docs/modulos.md`.

## Complexity Tracking

Sin violaciones que justificar.
