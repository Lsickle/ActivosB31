# Feature Specification: Módulo Configuración (catálogos base)

**Feature Branch**: `002-modulo-configuracion` (se trabaja sobre `main`; la rama es opcional)

**Created**: 2026-10-17

**Status**: Draft

**Input**: User description: "Módulo Configuración de ActivosB31: CRUD administrable (crear, listar con búsqueda, editar, activar/desactivar; nunca borrar) de categorías de activo, tipos de activo (cada tipo pertenece a una categoría, 1:N, con modo de control serializado o por cantidad), tipos de ubicación y ubicaciones (lista plana, cada una con un tipo). Reglas: RN-002, RN-003, RN-004, RN-007."

Reglas de negocio de referencia (`docs/modulos.md`): **RN-002** (ubicaciones planas con tipo), **RN-003** (no se borra, solo se desactiva), **RN-004** (tipos de ubicación administrables), **RN-007** (el tipo de activo define si se controla serializado o por cantidad), **RN-011** (todos consultan, solo el Administrador modifica), **RN-012** (catálogos precargados).

**Depende de**: `specs/001-rbac` (roles, permisos `configuracion.ver` / `configuracion.gestionar`).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Administrar categorías de activo (Priority: P1)

Un usuario del sistema registra las familias en que la estación agrupa sus bienes (p. ej. "EPP", "Equipos de respiración", "Herramientas de rescate"), las consulta, corrige su nombre o descripción y desactiva las que ya no se usan.

**Why this priority**: Las categorías son la raíz de la clasificación; sin ellas no se pueden crear tipos de activo ni, después, activos.

**Independent Test**: Crear, buscar, editar, desactivar y reactivar una categoría desde la pantalla de Configuración, sin depender de ningún otro catálogo.

**Acceptance Scenarios**:

1. **Given** no existe la categoría "EPP", **When** el usuario la crea con nombre y descripción opcional, **Then** aparece en el listado como activa.
2. **Given** existe la categoría "EPP", **When** el usuario intenta crear "epp" (u otra variante de mayúsculas), **Then** el sistema rechaza el registro indicando que el nombre ya existe.
3. **Given** existe una categoría activa, **When** el usuario la desactiva, **Then** queda marcada como inactiva, sigue visible en el listado (con filtro de estado) y conserva sus tipos sin modificarlos (RN-003).
4. **Given** el listado tiene varias categorías, **When** el usuario escribe parte de un nombre en la búsqueda, **Then** solo se muestran las coincidencias, sin distinguir mayúsculas ni tildes en lo posible.
5. **Given** cualquier categoría, **When** el usuario busca la opción de eliminar, **Then** no existe: solo se puede desactivar (RN-003).

---

### User Story 2 - Administrar tipos de activo (Priority: P1)

El usuario define los tipos concretos dentro de cada categoría (p. ej. "Cilindro SCBA" en "Equipos de respiración", "Guantes de rescate" en "EPP") e indica si los artículos de ese tipo se controlan **uno a uno (serializado)** o **por cantidad** (RN-007).

**Why this priority**: El tipo y su modo de control determinan cómo se registrarán los activos y el inventario; es requisito directo del módulo de Activos.

**Independent Test**: Con al menos una categoría activa, crear tipos, filtrarlos por categoría, editarlos y desactivarlos.

**Acceptance Scenarios**:

1. **Given** existe la categoría activa "EPP", **When** el usuario crea el tipo "Guantes de rescate" con modo "por cantidad", **Then** el tipo queda asociado a "EPP", activo y con ese modo.
2. **Given** existe "Guantes de rescate" en "EPP", **When** el usuario crea otro tipo con el mismo nombre (sin distinguir mayúsculas) en "EPP", **Then** se rechaza; **When** lo crea en otra categoría, **Then** se acepta.
3. **Given** la categoría "Herramientas" está inactiva, **When** el usuario crea un tipo nuevo o mueve un tipo a otra categoría, **Then** "Herramientas" no aparece entre las opciones (RN-003).
4. **Given** la categoría "Herramientas" está inactiva y tiene tipos, **When** se consultan esos tipos en el listado, **Then** siguen visibles con su estado propio y se indica que su categoría está inactiva; no se ofrecen para registros nuevos de activos.
5. **Given** un tipo existente, **When** el usuario cambia su modo de control y el tipo aún no tiene activos registrados, **Then** el cambio se guarda.
6. **Given** el formulario de tipo, **When** el usuario no elige modo de control, **Then** el sistema no permite guardar.

---

### User Story 3 - Administrar tipos de ubicación y ubicaciones (Priority: P2)

El usuario define los tipos de ubicación (p. ej. "Bodega", "Vehículo", "Oficina") y luego las ubicaciones concretas (p. ej. "Bodega principal" de tipo Bodega, "Máquina 1" de tipo Vehículo). La lista de ubicaciones es plana: no hay ubicaciones dentro de otras (RN-002, RN-004).

**Why this priority**: Las ubicaciones se necesitan para registrar activos e inventario, pero pueden cargarse después de categorías y tipos.

**Independent Test**: Crear un tipo de ubicación, crear ubicaciones de ese tipo, editarlas, filtrarlas por tipo y desactivarlas.

**Acceptance Scenarios**:

1. **Given** no hay tipos de ubicación, **When** el usuario intenta crear una ubicación, **Then** el formulario indica que primero debe existir al menos un tipo de ubicación activo.
2. **Given** existe el tipo activo "Vehículo", **When** el usuario crea la ubicación "Máquina 1" de ese tipo, **Then** queda activa y asociada a "Vehículo".
3. **Given** existe la ubicación "Máquina 1", **When** se intenta crear "máquina 1", **Then** se rechaza por nombre duplicado.
4. **Given** el tipo de ubicación "Oficina" está inactivo, **When** el usuario crea o edita una ubicación, **Then** "Oficina" no aparece entre las opciones, pero las ubicaciones existentes de ese tipo conservan su asociación.

---

### Edge Cases

- Nombre vacío, solo espacios o mayor a la longitud máxima (120 caracteres): se rechaza. Los espacios al inicio y al final se eliminan antes de validar unicidad.
- Dos usuarios crean al mismo tiempo el mismo nombre: solo uno se guarda; el otro recibe el mensaje de duplicado.
- Reactivar un registro cuyo nombre coincide con otro: no ocurre, porque la unicidad aplica también a registros inactivos.
- Editar un tipo de activo cuya categoría está inactiva: se puede editar nombre, descripción y modo, pero si se cambia la categoría solo se ofrecen categorías activas.
- Una sesión expirada o una cuenta desactivada intenta guardar: la operación se rechaza y el usuario vuelve al inicio de sesión.
- Listados con muchos registros: se paginan.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST permitir crear, listar, buscar por nombre, editar, desactivar y reactivar **categorías de activo** (nombre obligatorio, descripción opcional).
- **FR-002**: El sistema MUST permitir crear, listar, buscar, filtrar por categoría y por estado, editar, desactivar y reactivar **tipos de activo**; cada tipo pertenece a exactamente una categoría (1:N) y tiene un **modo de control** obligatorio: serializado o por cantidad (RN-007).
- **FR-003**: El sistema MUST permitir crear, listar, buscar, editar, desactivar y reactivar **tipos de ubicación** (RN-004).
- **FR-004**: El sistema MUST permitir crear, listar, buscar, filtrar por tipo y por estado, editar, desactivar y reactivar **ubicaciones**; cada ubicación tiene exactamente un tipo de ubicación y no puede contener otras ubicaciones (RN-002).
- **FR-005**: El sistema MUST NOT ofrecer eliminación física en ninguno de los cuatro catálogos; solo desactivación (RN-003).
- **FR-006**: Los nombres MUST ser únicos sin distinguir mayúsculas: globalmente en categorías, tipos de ubicación y ubicaciones; por categoría en tipos de activo. La unicidad incluye registros inactivos.
- **FR-007**: Al crear o editar un tipo de activo, el sistema MUST ofrecer solo categorías activas; al crear o editar una ubicación, solo tipos de ubicación activos.
- **FR-008**: Desactivar una categoría MUST NOT desactivar sus tipos; los tipos de una categoría inactiva MUST considerarse no disponibles para registros nuevos (RN-003). Lo mismo aplica a ubicaciones de un tipo de ubicación inactivo.
- **FR-009**: El modo de control de un tipo de activo MUST poder cambiarse solo mientras el tipo no tenga activos registrados (la verificación se activa cuando exista el registro de activos).
- **FR-010**: El sistema MUST exponer, para uso de otros módulos, la lista de opciones *disponibles* de cada catálogo (solo registros activos y, en tipos de activo, solo de categorías activas).
- **FR-011**: Consultar los catálogos MUST requerir el permiso "ver configuración"; crear, editar, desactivar o reactivar MUST requerir "gestionar configuración" (RN-009, RN-011). Inicialmente todos los roles tienen "ver" y solo el Administrador "gestionar". La interfaz MUST ocultar las acciones de modificación a quien no tenga "gestionar", y el sistema MUST rechazarlas aunque se intenten directamente.
- **FR-014**: El sistema MUST precargar, de forma que repetir la carga no duplique registros, las categorías, tipos de activo y tipos de ubicación iniciales listados en "Datos iniciales" (RN-012).
- **FR-012**: Los mensajes de validación MUST mostrarse en español y junto al campo correspondiente.
- **FR-013**: Los listados MUST paginarse y ordenarse por nombre por defecto.

### Key Entities

- **Categoría de activo**: familia de bienes. Nombre único, descripción opcional, estado activo/inactivo. Tiene muchos tipos de activo.
- **Tipo de activo**: clase concreta de bien dentro de una categoría. Nombre (único dentro de su categoría), descripción opcional, modo de control (serializado / por cantidad), estado. Pertenece a una categoría.
- **Tipo de ubicación**: clasificación de ubicaciones (bodega, vehículo, oficina…). Nombre único, descripción opcional, estado. Tiene muchas ubicaciones.
- **Ubicación**: lugar físico donde puede estar un activo o existencia. Nombre único, descripción opcional, estado. Pertenece a un tipo de ubicación; no tiene ubicación padre.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario registra una categoría con tres tipos de activo en menos de 3 minutos sin ayuda.
- **SC-002**: El 100 % de los intentos de crear un nombre duplicado (cualquier combinación de mayúsculas) se rechaza con un mensaje que explica la causa.
- **SC-003**: Ningún registro de los cuatro catálogos puede eliminarse desde la aplicación; el 100 % de los registros desactivados sigue consultable.
- **SC-004**: La búsqueda en un catálogo de 500 registros muestra resultados en menos de 1 segundo percibido por el usuario.
- **SC-005**: Las opciones disponibles que reciben otros módulos nunca incluyen categorías, tipos ni ubicaciones inactivos.

## Datos iniciales (RN-012) — confirmados por el usuario (2026-10-17)

S = serializado, C = por cantidad (RN-007).

| Categoría | Tipos de activo |
|---|---|
| Equipos de protección personal (EPP) | Casco estructural (S), Chaqueta estructural (S), Pantalón estructural (S), Botas estructurales (C), Guantes estructurales (C), Monja / capucha (C), Guantes de rescate (C) |
| Equipos de respiración autónoma | Equipo SCBA (S), Cilindro SCBA (S), Máscara facial (S) |
| Herramientas de rescate | Equipo hidráulico de rescate (S), Motosierra (S), Motor de corte / tronzadora (S), Herramientas manuales de entrada forzada (C), Cuerdas de rescate (S) |
| Equipos de extinción | Manguera 1½" (C), Manguera 2½" (C), Boquilla / pitón (C), Extintor portátil (S) |
| Comunicaciones | Radio portátil (S), Radio móvil / base (S) |
| Atención prehospitalaria | Desfibrilador DEA (S), Camilla (S), Inmovilizadores (C), Insumos médicos (C) |
| Vehículos | Máquina extintora (S), Ambulancia (S), Vehículo de rescate (S), Camioneta (S) |
| Uniformes y dotación | Camisa de dotación (C), Pantalón de dotación (C), Botas de dotación (C) |
| Consumibles | Agente espumógeno (C), Baterías y pilas (C) |

Tipos de ubicación: **Bodega, Vehículo, Oficina, Otro**. No se precargan ubicaciones concretas.

## Assumptions

- La periodicidad de mantenimientos y vencimientos (RN-006) se define con el módulo de Activos; no forma parte de este módulo.
- La regla de FR-009 queda preparada pero sin efecto hasta que exista el registro de activos (hoy ningún tipo tiene activos).
- La pantalla de Configuración agrupa los cuatro catálogos en una sola sección con pestañas; el diseño visual sigue el layout actual de la aplicación, no el prototipo de v0.
- No se requiere bitácora de cambios (auditoría) de los catálogos en esta versión; las marcas de fecha de creación y actualización son suficientes.
- Longitud máxima: 120 caracteres para nombres y 500 para descripciones.
