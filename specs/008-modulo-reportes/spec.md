# Feature Specification: Módulo Reportes y tablero de Inicio

**Feature Branch**: `008-modulo-reportes` (se trabaja sobre `main`)

**Created**: 2026-10-17

**Status**: Draft

**Input**: Reportes **por artículo**, **por empleado** e **inventario general**, exportables a **Excel y PDF** (RN-032) con **rango de fechas** (RN-033). Tablero de Inicio con alertas (mantenimientos, vencimientos, préstamos vencidos, stock bajo mínimo).

**Depende de**: 005, 006, 007.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Inventario general (Priority: P1)
Existencias por categoría, tipo, ubicación y estado (serializados por estado; por cantidad con total y mínimo), filtrable y exportable.

### User Story 2 - Reporte por empleado (Priority: P1)
Para un empleado: lo que tiene asignado o prestado hoy y el historial de entregas y devoluciones en un rango de fechas. Requiere además ver Empleados para mostrar sus datos personales (RN-022).

### User Story 3 - Reporte por artículo (Priority: P1)
Para un activo serializado: ficha, estado actual e historial (entregas, devoluciones, mantenimientos, cambios). Para un tipo por cantidad: existencias por ubicación y movimientos en el rango de fechas.

### User Story 4 - Tablero de Inicio (Priority: P2)
Contadores (activos por estado, entregas abiertas, préstamos vencidos) y listas de alertas: mantenimientos y vencimientos próximos/vencidos, préstamos vencidos, tipos bajo mínimo. Cada usuario ve solo las tarjetas de los módulos que puede ver.

**Acceptance Scenarios (todas)**:
1. **Given** un reporte en pantalla, **When** se exporta, **Then** el Excel y el PDF contienen exactamente los mismos datos y filtros que la pantalla, con fecha de generación y usuario.
2. **Given** un rango de fechas, **Then** los movimientos e historiales se limitan a ese rango (inclusive).
3. **Given** un usuario sin `reportes.ver`, **Then** no ve el módulo; el servidor rechaza los reportes.

## Requirements *(mandatory)*

- **FR-001**: Tres reportes con filtros (categoría, tipo, ubicación, estado, empleado, rango de fechas).
- **FR-002**: Exportación a Excel (.xlsx) y PDF (RN-032) del reporte filtrado.
- **FR-003**: Requiere `reportes.ver`; los datos personales de empleados además `empleados.ver` (RN-022).
- **FR-004**: Tablero de Inicio con alertas según permisos.

## Success Criteria *(mandatory)*

- **SC-001**: Un reporte de 1 000 filas se exporta en menos de 10 segundos.
- **SC-002**: Los totales del inventario general coinciden con las existencias del módulo Inventario.
