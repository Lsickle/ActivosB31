# Feature Specification: Bitácora de seguridad y bloqueo por intentos fallidos

**Feature Branch**: `003-seguridad-bitacora` (se trabaja sobre `main`)

**Created**: 2026-10-17

**Status**: Draft

**Input**: Pendiente de seguridad nº 3 de `CLAUDE.md` (hallazgo A-1 de la revisión de 001-rbac) y regla **RN-034**: bitácora de solo inserción de eventos de seguridad y bloqueo temporal de cuentas tras intentos fallidos. Requisito previo a cargar datos reales.

**Depende de**: `specs/001-rbac` (usuarios, roles, matriz, `usuarios.gestionar` exclusivo del Administrador, RN-019).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Bloqueo temporal tras intentos fallidos (Priority: P1)

Si alguien se equivoca 5 veces seguidas con la contraseña de una cuenta, la cuenta queda bloqueada 15 minutos (RN-034), aunque luego escriba la contraseña correcta. El Administrador puede desbloquearla antes.

**Why this priority**: protege las cuentas contra adivinación de contraseñas; es la medida acordada antes de datos reales.

**Independent Test**: fallar 5 veces con una cuenta, comprobar que la contraseña correcta no entra, desbloquear como Administrador y entrar.

**Acceptance Scenarios**:

1. **Given** una cuenta activa con 4 intentos fallidos seguidos, **When** se falla el 5.º, **Then** la cuenta queda bloqueada hasta 15 minutos después y se registra el evento "cuenta bloqueada".
2. **Given** una cuenta bloqueada, **When** se ingresa la contraseña correcta antes de los 15 minutos, **Then** el ingreso se rechaza con el mismo mensaje genérico que una contraseña errada.
3. **Given** una cuenta bloqueada cuyo plazo ya venció, **When** se ingresa la contraseña correcta, **Then** entra y el contador de fallos vuelve a cero.
4. **Given** una cuenta con 3 fallos, **When** ingresa correctamente, **Then** el contador vuelve a cero (los fallos deben ser **seguidos**).
5. **Given** una cuenta bloqueada, **When** el Administrador la desbloquea desde Usuarios, **Then** puede entrar de inmediato y se registra "cuenta desbloqueada" con quién lo hizo.
6. **Given** el listado de usuarios, **When** una cuenta está bloqueada, **Then** se muestra "Bloqueada hasta HH:MM" y la acción "Desbloquear" (solo con gestionar Usuarios).

---

### User Story 2 - Bitácora de seguridad (Priority: P1)

El sistema registra automáticamente quién hizo qué en temas de seguridad: ingresos exitosos y fallidos, cierres de sesión, bloqueos y desbloqueos, creación y edición de usuarios, cambios de rol o estado, restablecimientos y cambios de contraseña, creación y edición de roles y cambios en la matriz de permisos. Nadie puede modificar ni borrar estos registros.

**Why this priority**: trazabilidad exigida antes de datos reales (A-1); permite investigar accesos indebidos.

**Independent Test**: realizar cada acción y verificar que aparece en la bitácora con fecha, actor, afectado, IP y detalle.

**Acceptance Scenarios**:

1. **Given** cualquier acción de la lista, **When** ocurre, **Then** queda un registro con fecha y hora, tipo de evento, quién la hizo, a quién afectó, dirección IP, navegador y detalle (p. ej. rol anterior y nuevo, permisos agregados y quitados).
2. **Given** un ingreso fallido con un usuario inexistente, **When** se registra, **Then** guarda el texto intentado sin asociarlo a ninguna cuenta. La contraseña intentada **nunca** se guarda.
3. **Given** un registro de la bitácora, **When** cualquiera intenta modificarlo o borrarlo (incluso directamente en la base de datos desde la aplicación), **Then** la operación se rechaza.
4. **Given** una acción que falla (p. ej. validación rechazada), **When** termina, **Then** no se registra como realizada.

---

### User Story 3 - Consultar la bitácora (Priority: P2)

El Administrador consulta la bitácora desde Usuarios y roles, filtra por tipo de evento, usuario (actor o afectado) y rango de fechas, y ve el detalle de cada evento.

**Why this priority**: sin consulta la bitácora solo sirve con acceso a la base de datos.

**Independent Test**: generar eventos y encontrarlos con cada filtro.

**Acceptance Scenarios**:

1. **Given** un usuario con gestionar Usuarios (solo el Administrador, RN-019), **When** abre la pestaña "Bitácora", **Then** ve los eventos del más reciente al más antiguo, paginados.
2. **Given** un usuario sin ese permiso, **When** intenta consultar la bitácora, **Then** no ve la pestaña y el servidor rechaza la consulta.
3. **Given** filtros de tipo, usuario y fechas, **When** se aplican, **Then** solo aparecen los eventos que cumplen todos.

### Edge Cases

- Fallos contra una cuenta **inactiva** cuentan igual y la respuesta sigue siendo el mensaje genérico.
- Fallos con un usuario inexistente: se registran, pero no bloquean nada.
- El bloqueo aplica a todo ingreso (web y token para móvil). No cierra sesiones que ya estaban abiertas.
- Dos intentos fallidos simultáneos cuentan ambos (no se pierden incrementos).
- Desbloquear una cuenta que no está bloqueada: no hace nada y no registra evento.
- Las IP y navegadores son datos personales (Ley 1581): solo los ve quien consulta la bitácora (Administrador).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST contar los intentos fallidos **seguidos** por cuenta existente y, al llegar a **5**, bloquearla **15 minutos** (RN-034).
- **FR-002**: Mientras una cuenta esté bloqueada, el ingreso MUST rechazarse con el **mismo mensaje genérico** de credenciales inválidas (sin revelar que existe ni que está bloqueada). La pantalla de ingreso MUST indicar siempre, como texto fijo, que varias equivocaciones seguidas bloquean la cuenta temporalmente.
- **FR-003**: Un ingreso exitoso MUST poner el contador en cero.
- **FR-004**: Quien tenga gestionar Usuarios MUST poder desbloquear una cuenta; el listado MUST mostrar el estado de bloqueo.
- **FR-005**: El sistema MUST registrar en la bitácora los eventos: ingreso exitoso, ingreso fallido, cierre de sesión, cuenta bloqueada, cuenta desbloqueada, usuario creado, usuario editado (incluido cambio de rol), usuario activado/desactivado, contraseña restablecida por el Administrador, contraseña cambiada por el usuario, rol creado, rol editado, rol activado/desactivado y matriz de permisos modificada.
- **FR-006**: Cada evento MUST guardar fecha y hora, tipo, actor (si lo hay), usuario y/o rol afectado, IP, navegador y detalle del cambio. MUST NOT guardar contraseñas ni tokens.
- **FR-007**: Los registros de la bitácora MUST ser de solo inserción: ni la aplicación ni una consulta directa del usuario de la aplicación a la base de datos pueden modificarlos o borrarlos.
- **FR-008**: La bitácora MUST consultarse solo con gestionar Usuarios (exclusivo del Administrador, RN-019), con filtros por tipo, usuario y rango de fechas, ordenada del más reciente al más antiguo y paginada.
- **FR-009**: Un evento MUST registrarse solo si la acción se completó (misma transacción).

### Key Entities

- **Evento de seguridad**: tipo, momento, actor, usuario o rol afectado, texto de ingreso intentado (solo en fallidos), IP, navegador, detalle. Inmutable.
- **Usuario** (existente): agrega contador de fallos seguidos y "bloqueado hasta".

## Success Criteria *(mandatory)*

- **SC-001**: El 100 % de las acciones listadas en FR-005 genera exactamente un evento.
- **SC-002**: Tras 5 fallos seguidos, el 100 % de los ingresos durante los 15 minutos siguientes se rechaza, incluida la contraseña correcta.
- **SC-003**: Ningún registro de la bitácora puede modificarse ni borrarse desde la aplicación.
- **SC-004**: El Administrador encuentra los eventos de un usuario en un día dado en menos de 30 segundos.

## Assumptions

- Umbral y duración (5 / 15 min) fijos en configuración del servidor, no editables desde la interfaz (RN-034).
- Retención indefinida; no hay exportación de la bitácora en esta versión.
- La recuperación de contraseña por correo sigue pendiente (no hay servicio de correo).
- Los eventos de los módulos de negocio (activos, entregas…) no van aquí: cada módulo lleva su propio historial.
