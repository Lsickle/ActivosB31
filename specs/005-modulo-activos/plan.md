# Implementation Plan: Módulo Activos

**Branch**: `005-modulo-activos` (sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

## Summary

Activos serializados (`assets`) con placa única, estados RN-005, ubicación y poseedor (RN-039), foto privada re-codificada sin EXIF (pregunta 25), historial inmutable (`asset_events`), planes de mantenimiento por activo (`asset_maintenance_plans`, RN-029/RN-030) con registros inmutables, vencimientos (RN-031) y alertas; ajustes en `app_settings`; `asset_types.min_stock` (RN-028). Activa FR-009 de 002 (`AssetType::hasAssets()`).

## Technical Context

- Dependencias nuevas (verificadas en `docs/infraestructura.md`): `intervention/image ^4.4` + `intervention/image-laravel ^4.1.1` (driver GD); `league/flysystem-aws-s3-v3 ^3.35.3` para R2 (en local y tests se usa disco `local` privado).
- Disco configurable `ASSETS_DISK` (def. `local`; producción `r2`). Fotos en `assets/{asset_id}/{uuid}.jpg`, máx. 1600 px de lado, JPEG calidad 82, sin metadatos. URLs con `temporaryUrl()` de 10 minutos (el disco local de Laravel 13 lo soporta con `serve => true`).
- Inmutabilidad de `asset_events` y `asset_maintenance_records` con el mismo trigger plpgsql de 003 (función reutilizable `prevent_row_changes()`).

## Constitution Check

| Principio | Estado |
|---|---|
| I. Esquema | ✅ Bloque 005 aprobado. Se agregan índices (`status`, `location_id`, `holder_employee_id`, `next_due_on`, `expires_on`). |
| II. Trazabilidad | ✅ RN-001, RN-005..RN-008, RN-028..RN-031, RN-039. |
| III. No reinterpretación | ✅ |
| IV. TDD | ✅ |
| V. Seguridad | ⚠️ Subida de archivos: revisión de `especialista-seguridad` del manejo de fotos. |

## Decisiones

- **D-001 Transiciones** (`App\Enums\AssetStatus`): manuales Disponible ⇄ En mantenimiento; Disponible/En mantenimiento → Dado de baja (motivo obligatorio, definitivo). `assigned` solo desde Entregas (007) mediante una acción `AssignAsset`/`ReleaseAsset` que este módulo expone. Ubicación y estado no se cambian si está Asignado (409).
- **D-002 Historial**: acción `RecordAssetEvent` en la misma transacción; eventos `created`, `updated` (`{changes}`), `status_changed`, `location_changed`, `maintenance_done`, `retired`, (`delivered`, `returned` desde 007).
- **D-003 Próximo mantenimiento**: `next_due_on = (last_done_on ?? fecha de creación del plan) + interval_months`, recalculado al registrar un mantenimiento (si su fecha es posterior al último) y al editar el plan.
- **D-004 Alertas**: `GET /api/assets/alerts` → `maintenance` (planes activos de activos no dados de baja con `next_due_on <= hoy + alert_days`) y `expirations` (`expires_on <= hoy + expiry_alert_days`), cada uno con `overdue` bool. Hoy en `America/Bogota`.
- **D-005 Ajustes**: `app_settings` con `expiry_alert_days` (def. 30). `GET/PUT /api/settings` — ver con `configuracion.ver`, cambiar con `configuracion.gestionar`.
- **D-006 Placa**: recortada y en mayúsculas; único `lower(plate)`.
- **D-007 Tipo**: solo tipos `serialized`, activos y de categoría activa al crear; al editar se puede conservar el actual.
- **D-008 Stock mínimo**: `asset_types.min_stock` (entero ≥ 0, solo `quantity`; con `serialized` se rechaza) editable en el formulario de tipos de Configuración.

## Contrato (resumen)

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/api/assets` (`search` placa/serial/marca/modelo, `asset_category_id`, `asset_type_id`, `status`, `location_id`, `holder_employee_id`, paginado) | activos.ver |
| GET | `/api/assets/{id}` (con plan(es), última foto URL temporal) | activos.ver |
| POST | `/api/assets` (multipart: datos + `photo?`) | activos.gestionar |
| PATCH/POST | `/api/assets/{id}` (datos) · `POST /api/assets/{id}/photo` · `DELETE /api/assets/{id}/photo` | activos.gestionar |
| PATCH | `/api/assets/{id}/status` `{status, reason?}` · `/api/assets/{id}/location` `{location_id}` | activos.gestionar |
| GET | `/api/assets/{id}/events` (paginado, desc) | activos.ver |
| GET/POST/PATCH | `/api/assets/{id}/maintenance-plans[/{plan}]` (`name, interval_months, last_done_on?, alert_days?, is_active`) | ver / gestionar |
| GET/POST | `/api/assets/{id}/maintenance-records` (`plan_id?, performed_on, description, performed_by?, cost?`) | ver / gestionar |
| GET | `/api/assets/alerts` | activos.ver |
| GET/PUT | `/api/settings` | configuracion.ver / .gestionar |

`AssetResource`: `id, plate, serial, brand, model, acquisition_date, acquisition_value, useful_life_months, expires_on, status, status_label, notes, photo_url|null, asset_type{id,name,tracking_mode,category{id,name}}, location{id,name,is_active}|null, holder{id, full_name, position}|null, next_maintenance_on|null, created_at, updated_at`.

## Frontend

`/activos`: listado con filtros y búsqueda; ficha `/activos/[id]` con datos, foto, acciones de estado/ubicación/baja, planes de mantenimiento, registrar mantenimiento, historial; formulario alta/edición con foto (vista previa). Tarjeta "Alertas" en la pantalla de Activos. Campo "Stock mínimo" en el formulario de tipos por cantidad (Configuración) y "Anticipación de vencimientos" en Configuración.

## Orden

1. Dependencias + disco + migraciones + enums + modelos + factories + `esquema-bd`.
2. CRUD de activos + foto + historial. 3. Estados/ubicación/baja. 4. Planes y registros de mantenimiento. 5. Alertas y ajustes. 6. `min_stock` y FR-009 de 002. 7. Frontend. 8. Seguridad (fotos), QA, navegador, commit.
