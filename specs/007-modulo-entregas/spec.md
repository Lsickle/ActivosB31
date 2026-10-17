# Feature Specification: Módulo Entregas (dotación y préstamos con firma)

**Feature Branch**: `007-modulo-entregas` (se trabaja sobre `main`)

**Created**: 2026-10-17

**Status**: Draft — pendiente de aprobación del esquema (bloque 007) y de las preguntas 21 y 22

**Input**: Entrega de activos a empleados como **dotación** o **préstamo** (RN-023), con **firma en pantalla** (RN-024), **varios artículos** serializados y por cantidad (RN-025) y **devoluciones con estado** (RN-026).

**Depende de**: 004 (empleados), 005 (activos), 006 (existencias por cantidad).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrar una entrega firmada (Priority: P1)

El Almacenista elige el empleado (activo), el tipo de entrega (dotación o préstamo con fecha de devolución esperada), la ubicación de origen y los artículos: activos serializados disponibles y cantidades de artículos por cantidad. El empleado firma en la pantalla y se guarda la entrega con su consecutivo.

**Acceptance Scenarios**:

1. **Given** un casco disponible y 20 guantes en la bodega, **When** se entrega el casco y 2 pares de guantes como dotación con firma, **Then** se crea "ENT-000001", el casco pasa a **Asignado** a ese empleado y la bodega queda con 18 guantes.
2. **Given** un préstamo, **When** no se indica fecha de devolución esperada, **Then** no se permite guardar.
3. **Given** el formulario, **When** no hay firma, **Then** no se permite guardar (RN-024).
4. **Given** un activo Asignado, En mantenimiento o Dado de baja, **Then** no se ofrece.
5. **Given** cantidades mayores a la existencia en la ubicación de origen, **Then** se rechaza toda la entrega (todo o nada).
6. **Given** una entrega guardada, **When** se busca editarla o borrarla, **Then** no existe la opción.

---

### User Story 2 - Devoluciones con estado (Priority: P1)

Se registra la devolución total o parcial de lo entregado, indicando por artículo su estado: **bueno**, **dañado** o **perdido** (RN-026).

**Acceptance Scenarios**:

1. **Given** un casco asignado, **When** se devuelve "bueno" a una ubicación, **Then** queda Disponible en esa ubicación y sin empleado.
2. **Given** un casco asignado, **When** se devuelve "dañado", **Then** queda **En mantenimiento**.
3. **Given** un casco asignado, **When** se reporta "perdido" con observación, **Then** queda **Dado de baja** con ese motivo.
4. **Given** 2 pares de guantes entregados, **When** se devuelve 1 bueno, **Then** vuelve 1 a la ubicación elegida y queda 1 pendiente.
5. **Given** artículos por cantidad devueltos dañados o perdidos, **Then** no vuelven al stock y quedan registrados como pérdida (pregunta 21).
6. **Given** que todo lo de una entrega fue devuelto, **Then** la entrega se muestra "Cerrada".

---

### User Story 3 - Préstamos vencidos y consulta (Priority: P2)

El listado de entregas se filtra por empleado, tipo, estado (abierta, cerrada, vencida) y fechas. Los préstamos con artículos pendientes y fecha esperada pasada aparecen como **vencidos** y en las alertas de Inicio (RN-026). La ficha de la entrega muestra artículos, devoluciones y la firma.

**Acceptance Scenarios**:

1. **Given** un préstamo con fecha esperada ayer y artículos pendientes, **Then** aparece como vencido.
2. **Given** la ficha de un empleado (con ver Empleados), **Then** muestra lo que tiene asignado o prestado.

### Edge Cases

- Un activo no puede estar en dos entregas abiertas a la vez.
- La firma es una imagen privada (almacenamiento privado, enlace temporal).
- Empleado desactivado con artículos pendientes: se permite desactivarlo pero se advierte; las devoluciones siguen siendo posibles.
- Quien no tiene ver Empleados ve solo nombre y cargo del empleado en las entregas (RN-022).

## Requirements *(mandatory)*

- **FR-001**: Entregas inmutables con consecutivo `ENT-000001`, empleado activo, tipo (dotación/préstamo), fecha esperada obligatoria en préstamos, ubicación de origen, uno o más artículos, firma obligatoria, observaciones.
- **FR-002**: Al entregar: serializados → Asignado con el empleado; por cantidad → movimiento de inventario de salida por entrega; todo en una transacción.
- **FR-003**: Devoluciones parciales o totales por artículo con estado bueno / dañado / perdido y efectos de RN-026.
- **FR-004**: Estado de la entrega derivado: abierta, cerrada, vencida.
- **FR-005**: Alertas de préstamos vencidos.
- **FR-006**: Ver requiere `entregas.ver`; registrar entregas y devoluciones, `entregas.gestionar`.
- **FR-007**: Todo queda en el historial de cada activo y en los movimientos de inventario.

## Success Criteria *(mandatory)*

- **SC-001**: Una entrega de 5 artículos con firma se registra en menos de 3 minutos.
- **SC-002**: Ningún activo queda asignado a dos empleados ni ninguna existencia negativa.
- **SC-003**: El 100 % de los préstamos vencidos aparece en alertas.
