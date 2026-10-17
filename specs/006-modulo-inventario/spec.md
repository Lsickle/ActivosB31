# Feature Specification: Módulo Inventario (artículos por cantidad, movimientos y conteos)

**Feature Branch**: `006-modulo-inventario` (se trabaja sobre `main`)

**Created**: 2026-10-17

**Status**: Draft — pendiente de aprobación del esquema (bloque 006) y de la pregunta 20

**Input**: Existencias por ubicación de los artículos controlados por cantidad, movimientos que no se editan ni borran (se corrigen con un movimiento inverso), conteos físicos por ubicación (**RN-027**) y stock mínimo con alerta (**RN-028**). También muestra la existencia de los serializados por ubicación y estado.

**Depende de**: 001, 002 (tipos por cantidad, ubicaciones), 005 (activos serializados y stock mínimo en el tipo).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Entradas, salidas, traslados y ajustes (Priority: P1)

El Almacenista registra la entrada de artículos por cantidad a una ubicación (compra, donación), su salida (consumo, daño, pérdida), su traslado entre ubicaciones y ajustes con motivo. Las existencias se actualizan al instante.

**Acceptance Scenarios**:

1. **Given** 0 "Guantes de rescate" en "Bodega principal", **When** se registra una entrada de 20, **Then** la existencia queda en 20 y el movimiento queda registrado con usuario y fecha.
2. **Given** 20 en la bodega, **When** se intenta sacar 25, **Then** se rechaza: las existencias nunca quedan negativas.
3. **Given** 20 en la bodega, **When** se trasladan 5 a "Máquina 1", **Then** bodega 15 y Máquina 1 5, en una sola operación.
4. **Given** un movimiento equivocado, **When** se busca editarlo o borrarlo, **Then** no existe la opción; se registra un movimiento inverso con motivo.
5. **Given** dos salidas simultáneas que juntas superan la existencia, **Then** solo una se acepta.
6. **Given** el formulario, **When** se elige un tipo serializado, **Then** no se ofrece (RN-007).

---

### User Story 2 - Existencias (Priority: P1)

Cualquier usuario con ver Inventario consulta existencias por tipo, categoría y ubicación, y el total por tipo; ve los serializados agrupados por tipo, ubicación y estado.

**Acceptance Scenarios**:

1. **Given** existencias en varias ubicaciones, **When** se filtra por ubicación, **Then** se ven solo las de esa ubicación con su total.
2. **Given** un tipo por cantidad con stock mínimo 10 y total 8, **Then** aparece marcado "Bajo mínimo" y en las alertas de Inicio (RN-028).

---

### User Story 3 - Conteo físico por ubicación (Priority: P2)

El Almacenista abre un conteo de una ubicación: el sistema lista lo que debería haber (cantidades esperadas por tipo y activos serializados que deberían estar ahí); se registran las cantidades contadas y los serializados encontrados; al cerrar, las diferencias de cantidad se registran como ajustes (RN-027) y los serializados no encontrados se reportan.

**Acceptance Scenarios**:

1. **Given** 15 guantes esperados y 13 contados, **When** se cierra el conteo, **Then** se registra un ajuste de −2 con referencia al conteo y la existencia queda en 13.
2. **Given** un activo serializado no encontrado, **When** se cierra, **Then** se reporta como no encontrado y no cambia de estado automáticamente (pregunta 20).
3. **Given** un conteo abierto en una ubicación, **When** se intenta abrir otro en la misma, **Then** se rechaza.
4. **Given** movimientos durante un conteo abierto, **Then** se advierte al cerrar que las existencias cambiaron desde la apertura y se recalcula lo esperado.

### Edge Cases

- Ubicación inactiva: no recibe entradas ni traslados; sí puede vaciarse.
- Tipo inactivo: no recibe entradas; conserva existencias.
- Cantidades enteras positivas.
- Las entregas y devoluciones de artículos por cantidad (007) generan sus propios movimientos.

## Requirements *(mandatory)*

- **FR-001**: Movimientos de entrada, salida, traslado, ajuste (y entrega/devolución/pérdida desde Entregas) inmutables, con motivo opcional (obligatorio en salidas y ajustes).
- **FR-002**: Existencias por tipo y ubicación siempre ≥ 0, actualizadas atómicamente con cada movimiento.
- **FR-003**: Consulta de existencias y del historial de movimientos con filtros (tipo, categoría, ubicación, clase de movimiento, rango de fechas).
- **FR-004**: Conteos físicos por ubicación con cierre que genera ajustes (RN-027); un conteo abierto por ubicación.
- **FR-005**: Alerta de stock mínimo por tipo (RN-028).
- **FR-006**: Ver requiere `inventario.ver`; modificar, `inventario.gestionar`.

## Success Criteria *(mandatory)*

- **SC-001**: Ninguna existencia queda negativa ni se pierde un movimiento concurrente.
- **SC-002**: La suma de movimientos de un tipo en una ubicación siempre coincide con su existencia.
- **SC-003**: Un conteo de 50 renglones se registra y cierra en menos de 10 minutos.
