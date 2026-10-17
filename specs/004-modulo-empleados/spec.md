# Feature Specification: Módulo Empleados

**Feature Branch**: `004-modulo-empleados` (se trabaja sobre `main`)

**Created**: 2026-10-17

**Status**: Draft — pendiente de aprobación del esquema y de las preguntas 18, 19 y 23

**Input**: Personal de la estación al que se le asignan activos. Reglas **RN-021** (datos del empleado), **RN-022** (datos personales visibles solo con `empleados.ver`), **RN-015** (vínculo opcional usuario → empleado). Ley 1581 de 2012.

**Depende de**: `specs/001-rbac` (permisos `empleados.ver` / `empleados.gestionar`), `specs/002-modulo-configuracion` (patrón de catálogos y pantalla Configuración).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Registrar y mantener empleados (Priority: P1)

El usuario con gestionar Empleados registra a cada integrante de la estación con su documento, nombres, cargo o rango, compañía o turno, contacto, fecha de ingreso y tallas de dotación; lo edita y lo desactiva cuando se retira. Nunca se borra, porque conserva su historial de entregas.

**Why this priority**: sin empleados no hay entregas ni reporte por empleado.

**Independent Test**: crear, buscar, editar, desactivar y reactivar un empleado.

**Acceptance Scenarios**:

1. **Given** no existe un empleado con CC 1234567, **When** se registra con los datos obligatorios (tipo y número de documento, nombres, apellidos, cargo), **Then** queda activo.
2. **Given** existe CC 1234567, **When** se intenta registrar otro con CC 1234567, **Then** se rechaza; **When** se registra con CE 1234567, **Then** se acepta (la unicidad es tipo + número).
3. **Given** el listado, **When** se busca por parte del nombre, del apellido o del número de documento, **Then** aparecen las coincidencias (sin distinguir mayúsculas ni tildes).
4. **Given** un empleado activo, **When** se desactiva, **Then** sigue consultable con filtro de estado y no se ofrece para nuevas entregas.
5. **Given** cualquier empleado, **When** se busca la opción de eliminar, **Then** no existe.

---

### User Story 2 - Cargos y compañías como catálogos (Priority: P1)

Cargos o rangos y compañías o turnos se administran en Configuración (dos pestañas nuevas), con el mismo comportamiento que los demás catálogos: crear, editar, desactivar, sin borrar, nombres únicos.

**Why this priority**: el formulario de empleado los necesita.

**Independent Test**: crear un cargo y una compañía, usarlos en un empleado, desactivarlos y comprobar que ya no se ofrecen pero el empleado los conserva.

**Acceptance Scenarios**:

1. **Given** el cargo "Teniente" inactivo, **When** se crea o edita un empleado, **Then** "Teniente" no se ofrece, pero los empleados que ya lo tienen lo conservan.
2. **Given** la instalación inicial, **When** se abre la pestaña Cargos, **Then** aparecen los cargos precargados (pregunta 18).

---

### User Story 3 - Protección de datos personales (Priority: P1)

Solo quien tenga ver Empleados accede al módulo y a los datos personales (RN-022). El menú y la ruta se ocultan o niegan al resto.

**Why this priority**: obligación legal (Ley 1581 de 2012).

**Independent Test**: con un rol sin ver Empleados, comprobar que no aparece el menú, la ruta muestra "Acceso denegado" y el servidor rechaza la consulta.

**Acceptance Scenarios**:

1. **Given** un rol sin ver Empleados, **When** intenta consultar empleados por cualquier vía, **Then** se rechaza.
2. **Given** otros módulos (Entregas, Reportes) que muestran empleados, **When** el usuario no tiene ver Empleados, **Then** solo ven el nombre completo y el cargo, nunca documento, teléfono, correo ni tallas.

---

### User Story 4 - Vincular un usuario con un empleado (Priority: P3)

Al crear o editar un usuario del sistema, el Administrador puede vincularlo opcionalmente con un empleado activo que no tenga otro usuario vinculado (RN-015).

**Why this priority**: útil para trazabilidad, no bloquea nada.

**Independent Test**: vincular, ver el vínculo en ambos listados y desvincular.

**Acceptance Scenarios**:

1. **Given** un empleado ya vinculado a un usuario, **When** se intenta vincular a otro usuario, **Then** se rechaza.

### Edge Cases

- Número de documento con puntos o espacios: se guardan solo letras y dígitos, en mayúsculas (los pasaportes pueden tener letras).
- Correo y teléfono opcionales; si vienen, con formato válido.
- Fecha de ingreso futura: se rechaza.
- Tallas: texto corto libre (p. ej. "M", "42", "32x30").

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Crear, listar (paginado, orden por apellidos y nombres), buscar por nombre, apellido o documento, filtrar por cargo, compañía y estado, editar, desactivar y reactivar empleados. Sin eliminación.
- **FR-002**: Tipos de documento: CC, CE, TI, PA (pasaporte), PPT. Unicidad por tipo + número, incluidos inactivos.
- **FR-003**: Obligatorios: tipo y número de documento, nombres, apellidos, cargo. Opcionales: compañía, teléfono, correo, fecha de ingreso, tallas de camisa, pantalón, botas y guantes.
- **FR-004**: Cargos y compañías: catálogos en Configuración con las mismas reglas que 002 (configuracion.ver / configuracion.gestionar). Solo los activos se ofrecen en formularios.
- **FR-005**: El módulo y sus datos MUST requerir ver Empleados; las modificaciones, gestionar Empleados (RN-022).
- **FR-006**: El sistema MUST exponer a otros módulos una lista mínima de empleados activos (id, nombre completo, cargo) sin datos personales.
- **FR-007**: Vínculo opcional usuario → empleado, uno a uno (RN-015), gestionado desde Usuarios.

### Key Entities

- **Empleado**, **Cargo**, **Compañía/turno** (ver `esquema-bd`, propuesta 004).

## Success Criteria *(mandatory)*

- **SC-001**: Registrar un empleado completo toma menos de 2 minutos.
- **SC-002**: El 100 % de los intentos de documento duplicado se rechaza.
- **SC-003**: Ningún usuario sin ver Empleados obtiene documento, teléfono, correo o tallas de un empleado por ninguna vía.

## Assumptions

- Sin fotos de empleados ni carga masiva en esta versión.
- Los cargos precargados y el catálogo de compañías dependen de las respuestas 18 y 19; el permiso inicial de Consulta, de la 23.
