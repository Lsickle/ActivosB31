# Implementation Plan: Módulo Reportes y tablero de Inicio

**Branch**: `008-modulo-reportes` (sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

## Summary

Tres reportes consultables en pantalla y exportables (RN-032) a Excel (`maatwebsite/excel ^4.0.4`) y PDF (`barryvdh/laravel-dompdf ^3.1.2` + `dompdf/dompdf ^3.1.6`, `isRemoteEnabled=false`, plantillas Blade con tablas y CSS inline), con rango de fechas (RN-033). Tablero de Inicio que agrega las alertas de 005, 006 y 007 según los permisos del usuario.

## Decisiones

- **D-001 Una consulta por reporte**: clases `App\Reports\{InventoryReport,EmployeeReport,AssetReport,AssetTypeReport}` devuelven filas + totales; el JSON, el Excel y el PDF salen de la misma clase (SC: mismos datos).
- **D-002 Exportación**: `GET /api/reports/{reporte}?…&format=xlsx|pdf` responde el archivo (`Content-Disposition: attachment; filename="inventario-2026-10-17.xlsx"`) generado en memoria (sin colas: volúmenes pequeños, SC-001). Encabezado con filtros, fecha de generación (America/Bogota) y usuario.
- **D-003 Permisos**: `reportes.ver` en todos; el reporte por empleado incluye documento y contacto solo si el usuario tiene además `empleados.ver` (RN-022); si no, solo nombre y cargo.
- **D-004 Tablero**: `GET /api/dashboard` devuelve solo las secciones de los módulos que el usuario puede ver: `assets` (conteo por estado + alertas de 005), `inventory` (bajo mínimo), `deliveries` (abiertas, vencidas). Reemplaza la página Inicio actual.
- **D-005 PDF**: tamaño carta horizontal para inventario; vertical para fichas. Sin imágenes remotas; la foto del activo no se incluye en el PDF (evita lectura de archivos y CVE de dompdf).

## Contrato (resumen)

| Ruta | Filtros |
|---|---|
| `GET /api/reports/inventory` | `asset_category_id, asset_type_id, location_id, status` |
| `GET /api/reports/employees/{id}` | `from, to` |
| `GET /api/reports/assets/{id}` | `from, to` |
| `GET /api/reports/asset-types/{id}` | `from, to, location_id` |
| `GET /api/reports/asset-options` | `search` (placa o serial; para elegir el activo) |
| `GET /api/dashboard` | — |

Los reportes aceptan `format=json|xlsx|pdf` (por defecto `json`). Detalle en [contracts/api.md](contracts/api.md).

## Frontend

`/reportes` con tres pestañas (Inventario general, Por empleado, Por artículo): filtros, tabla en pantalla y botones "Excel" / "PDF" que descargan con la misma consulta. Inicio (`/`) con tarjetas de conteos y listas de alertas enlazadas a cada módulo.

## Tareas

- [x] T001 Instalar dependencias (versiones de `docs/infraestructura.md`), config dompdf segura
- [x] T002 Tests + clases de reporte (D-001) y endpoints JSON con permisos (D-003)
- [x] T003 Tests + exportación Excel y PDF (D-002, D-005)
- [x] T004 Tests + `/api/dashboard` (D-004)
- [x] T005 Frontend `/reportes` y descarga de archivos (+ tests). Contrato fijado en [contracts/api.md](contracts/api.md) (incluye `GET /api/reports/asset-options` y `Content-Disposition` en `exposed_headers` de CORS); `apiDownload` en `packages/app/lib/api-client.ts`.
- [x] T006 Frontend Inicio con alertas (+ tests)
- [ ] T007 Suites, navegador, `CLAUDE.md`
