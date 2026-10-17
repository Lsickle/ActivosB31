# Feature Specification: Módulo Activos (serializados, mantenimientos y vencimientos)

**Feature Branch**: `005-modulo-activos` (se trabaja sobre `main`)

**Created**: 2026-10-17

**Status**: Draft — pendiente de aprobación del esquema (bloque 005) y de las preguntas 22 y 25

**Input**: Registro de los bienes serializados de la estación con su historial, mantenimientos programados por activo y vencimientos. Reglas **RN-001, RN-005, RN-006, RN-007, RN-008, RN-029, RN-030, RN-031** y **RN-028** (stock mínimo, se configura en el tipo de activo).

**Depende de**: 001-rbac (`activos.ver` / `activos.gestionar`), 002-modulo-configuracion (tipos de activo, ubicaciones), 004-modulo-empleados (quién tiene el activo; lo fija Entregas).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrar activos serializados (Priority: P1)

El Almacenista registra cada bien identificable (casco, cilindro SCBA, radio, vehículo…) con su tipo, placa de inventario, serial del fabricante, marca, modelo, fecha y valor de adquisición, vida útil, fecha de vencimiento, ubicación, foto y observaciones.

**Why this priority**: es la base de entregas, mantenimientos y reportes.

**Independent Test**: registrar, buscar, editar y consultar un activo.

**Acceptance Scenarios**:

1. **Given** el tipo "Cilindro SCBA" (serializado), **When** se registra un activo con placa "B31-0001" y ubicación "Bodega principal", **Then** queda en estado **Disponible**.
2. **Given** existe la placa "B31-0001", **When** se registra otra con "b31-0001", **Then** se rechaza (RN-008).
3. **Given** el formulario, **When** se elige un tipo **por cantidad** (p. ej. "Guantes de rescate"), **Then** no se ofrece: esos artículos se manejan en Inventario (RN-007).
4. **Given** un tipo inactivo o de categoría inactiva, **When** se registra un activo, **Then** no se ofrece (RN-003).
5. **Given** el listado, **When** se busca por placa, serial, marca o modelo y se filtra por categoría, tipo, estado y ubicación, **Then** aparecen solo las coincidencias, paginadas.
6. **Given** un activo con foto, **When** se abre su ficha, **Then** se ve la foto (enlace temporal, no público).

---

### User Story 2 - Estados y baja (Priority: P1)

El Almacenista cambia el estado de un activo entre **Disponible**, **En mantenimiento** y **Dado de baja** (RN-005), y cambia su ubicación. **Asignado** solo lo ponen y quitan las entregas. La baja exige un motivo y es definitiva.

**Why this priority**: refleja la situación real de cada bien.

**Independent Test**: enviar a mantenimiento, devolver a disponible, trasladar, dar de baja.

**Acceptance Scenarios**:

1. **Given** un activo Disponible, **When** se envía a mantenimiento, **Then** queda En mantenimiento y no se ofrece para entregas.
2. **Given** un activo Asignado, **When** se intenta cambiar su estado o ubicación manualmente, **Then** se rechaza: primero debe devolverse en Entregas.
3. **Given** un activo Disponible o En mantenimiento, **When** se da de baja con motivo, **Then** queda Dado de baja, deja de ofrecerse en todo y no puede volver a otro estado.
4. **Given** cualquier cambio de estado, ubicación o datos, **When** ocurre, **Then** queda en el historial del activo con fecha, usuario y detalle.

---

### User Story 3 - Historial del activo (Priority: P1)

La ficha del activo muestra su historial completo: registro, ediciones, cambios de estado y ubicación, entregas y devoluciones (cuando exista Entregas), mantenimientos realizados y baja. El historial no se edita ni se borra.

**Independent Test**: realizar acciones sobre un activo y verlas en orden en su ficha.

---

### User Story 4 - Mantenimientos programados por activo (Priority: P2)

Para cada activo se definen planes de mantenimiento (p. ej. "Prueba hidrostática" cada 60 meses, "Revisión general" cada 12 meses) con la fecha del último realizado; el sistema calcula el siguiente (RN-029). Al registrar un mantenimiento realizado se actualiza el plan.

**Acceptance Scenarios**:

1. **Given** un plan cada 60 meses con último realizado el 2022-03-10, **Then** el próximo es 2027-03-10.
2. **Given** ese plan, **When** se registra un mantenimiento realizado el 2026-10-01, **Then** el próximo pasa a 2031-10-01 y el registro queda en el historial.
3. **Given** un plan sin fecha de último realizado, **Then** el próximo se calcula desde la fecha de creación del plan.
4. **Given** un plan con anticipación de 30 días (por defecto, editable por plan, RN-030), **When** faltan 30 días o menos o ya venció, **Then** aparece en las alertas.

---

### User Story 5 - Vencimientos y alertas (Priority: P2)

Los activos pueden tener **fecha de vencimiento** (vida útil del EPP, RN-031). El tablero de Inicio y la pantalla de Activos muestran alertas de mantenimientos y vencimientos próximos (anticipación configurable, 30 días por defecto, RN-030) y vencidos.

**Acceptance Scenarios**:

1. **Given** un casco con vencimiento en 20 días y anticipación global de 30, **Then** aparece como "Próximo a vencer".
2. **Given** un activo dado de baja, **Then** nunca aparece en alertas.
3. **Given** el Administrador, **When** cambia la anticipación global de vencimientos, **Then** las alertas se recalculan.

---

### User Story 6 - Stock mínimo por tipo (Priority: P3)

En Configuración, los tipos de activo **por cantidad** tienen un campo opcional "stock mínimo" (RN-028), que usa el módulo Inventario para alertar.

### Edge Cases

- Cambiar el modo de control de un tipo con activos: se rechaza (FR-009 de 002, que ahora se activa).
- Placa con espacios: se recortan; se guarda en mayúsculas.
- Valor de adquisición negativo, fecha de adquisición futura, vida útil ≤ 0: se rechazan.
- Foto: JPEG, PNG o WebP, máximo 4 MB (límite del proxy de Vercel; antes 5 MB, cambiado por la revisión de seguridad M-1) y 24 megapíxeles; reemplazar la foto borra el archivo anterior.
- Mover un activo a una ubicación inactiva: no se ofrece.
- Activo en un tipo que luego se desactiva: conserva su tipo; se sigue viendo con la indicación.

## Requirements *(mandatory)*

- **FR-001**: CRUD de activos serializados sin eliminación; campos de RN-008 y los confirmados (marca, modelo, fecha y valor de adquisición, vida útil opcional, vencimiento opcional, ubicación, foto, observaciones).
- **FR-002**: Placa obligatoria y única sin distinguir mayúsculas; serial opcional (RN-008). Solo tipos serializados activos de categorías activas (RN-007, RN-003).
- **FR-003**: Estados de RN-005. Transiciones manuales: Disponible ⇄ En mantenimiento; Disponible / En mantenimiento → Dado de baja (con motivo, definitivo). Asignado solo vía Entregas.
- **FR-004**: Historial inmutable por activo de toda acción (US3).
- **FR-005**: Planes de mantenimiento por activo con periodicidad en meses, último realizado, próximo calculado y anticipación (30 días por defecto); registro de mantenimientos realizados (fecha, descripción, quién lo hizo, costo opcional) inmutable.
- **FR-006**: Alertas de mantenimientos y vencimientos próximos y vencidos; anticipación global de vencimientos editable por el Administrador (configuracion.gestionar).
- **FR-007**: Ver requiere `activos.ver`; modificar, `activos.gestionar`.
- **FR-008**: Fotos en almacenamiento privado, entregadas con enlaces temporales.
- **FR-009**: Stock mínimo opcional en tipos por cantidad (RN-028).

## Success Criteria *(mandatory)*

- **SC-001**: Registrar un activo con foto toma menos de 2 minutos.
- **SC-002**: El 100 % de las placas duplicadas se rechaza.
- **SC-003**: Ningún registro del historial ni de mantenimientos realizados puede modificarse o borrarse.
- **SC-004**: Las alertas listan el 100 % de los activos no dados de baja con mantenimiento o vencimiento dentro de la anticipación.

## Assumptions

- Los artículos por cantidad no se registran aquí sino en Inventario (existencias por ubicación).
- Sin carga masiva desde Excel en esta versión.
- El tratamiento de la foto (quitar ubicación GPS) depende de la pregunta 25.
- Lo que pasa con la ubicación al entregar un activo depende de la pregunta 22.
