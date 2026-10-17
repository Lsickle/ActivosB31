# Feature Specification: Usuarios, roles y matriz de permisos (RBAC)

**Feature Branch**: `001-rbac` (se trabaja sobre `main`; la rama es opcional)

**Created**: 2026-10-17

**Status**: Draft

**Input**: User description: "Los catálogos los puede consultar cualquier rol pero solo el admin los puede modificar; la idea es que también haya una matriz que permita gestionar los permisos de cada módulo."

Reglas de negocio de referencia (`docs/modulos.md`): **RN-009** (permisos ver/gestionar por módulo en matriz rol × módulo), **RN-010** (roles precargados), **RN-013** (un rol por usuario), **RN-014** (contraseña temporal con cambio obligatorio), **RN-015** (usuarios independientes de empleados por ahora).

Base existente: inicio y cierre de sesión, consulta del usuario actual y bloqueo de cuentas inactivas ya funcionan; el usuario actual se informa hoy con una lista de permisos vacía.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Matriz de permisos por rol (Priority: P1)

El Administrador abre la matriz, ve los roles en columnas (o filas) y los módulos de la aplicación con dos casillas cada uno —**Ver** y **Gestionar**— y marca o desmarca qué puede hacer cada rol. Los cambios se aplican a todos los usuarios de ese rol.

**Why this priority**: Es el pedido explícito del usuario y lo que permite proteger todos los módulos que vienen (Configuración el primero).

**Independent Test**: Con los roles precargados, cambiar una casilla de "Consulta" y verificar que un usuario de ese rol gana o pierde el acceso correspondiente.

**Acceptance Scenarios**:

1. **Given** la matriz con los roles Administrador, Almacenista y Consulta, **When** el Administrador marca "Gestionar" en Empleados para Almacenista y guarda, **Then** los usuarios Almacenista pueden crear y editar empleados en su siguiente acción, sin volver a iniciar sesión.
2. **Given** un rol con "Gestionar" marcado en un módulo, **When** se desmarca "Ver" en ese módulo, **Then** "Gestionar" también se desmarca (gestionar implica ver).
3. **Given** un rol sin "Ver" en un módulo, **When** se marca "Gestionar", **Then** "Ver" se marca automáticamente.
4. **Given** la columna del rol Administrador, **When** cualquier usuario intenta desmarcar una casilla, **Then** no es posible: el Administrador siempre tiene todos los permisos (RN-010).
5. **Given** un usuario sin permiso de gestionar Usuarios y roles, **When** abre la matriz, **Then** la ve en solo lectura (si tiene "Ver") o no accede (si no lo tiene).

---

### User Story 2 - Gestión de usuarios (Priority: P1)

El Administrador crea cuentas para el personal que usará el sistema: nombre, nombre de usuario, correo, rol (uno solo) y una contraseña temporal. Puede editar los datos, cambiar el rol, restablecer la contraseña y desactivar o reactivar cuentas. No se borran usuarios.

**Why this priority**: Sin usuarios con roles distintos la matriz no tiene efecto práctico; hoy solo existe el usuario admin.

**Independent Test**: Crear un usuario con rol Consulta, iniciar sesión con él y comprobar que solo ve (no gestiona) los módulos.

**Acceptance Scenarios**:

1. **Given** el Administrador en Usuarios, **When** crea un usuario con rol Almacenista y contraseña temporal, **Then** la cuenta queda activa, con ese rol y marcada para cambio obligatorio de contraseña.
2. **Given** existe el usuario "jperez" con correo "jperez@…", **When** se intenta crear "JPerez" o el mismo correo con otras mayúsculas, **Then** se rechaza por duplicado.
3. **Given** un nombre de usuario que contiene "@", **When** se intenta guardar, **Then** se rechaza (el "@" se reserva para distinguir correo en el inicio de sesión).
4. **Given** un usuario activo con sesión abierta, **When** el Administrador lo desactiva, **Then** sus sesiones y accesos vigentes dejan de funcionar de inmediato.
5. **Given** el Administrador restablece la contraseña de un usuario, **When** ese usuario inicia sesión con la temporal, **Then** debe cambiarla antes de usar cualquier módulo, y sus sesiones anteriores quedan cerradas.
6. **Given** es el único Administrador activo, **When** alguien intenta desactivarlo o cambiarle el rol, **Then** el sistema lo impide.
7. **Given** un Administrador, **When** intenta desactivar su propia cuenta, **Then** el sistema lo impide.

---

### User Story 3 - Cambio de contraseña (Priority: P1)

Un usuario con contraseña temporal es llevado a una pantalla para definir su nueva contraseña al iniciar sesión. Cualquier usuario puede además cambiar su contraseña desde su perfil indicando la actual.

**Why this priority**: Es la mitad obligatoria de RN-014; sin esto la contraseña temporal sería permanente.

**Independent Test**: Iniciar sesión con un usuario recién creado y comprobar que solo puede acceder a la pantalla de cambio hasta completarlo.

**Acceptance Scenarios**:

1. **Given** un usuario con cambio obligatorio pendiente, **When** inicia sesión, **Then** solo puede acceder a la pantalla de cambio de contraseña y a cerrar sesión; cualquier otra acción se rechaza.
2. **Given** la pantalla de cambio, **When** el usuario repite la contraseña temporal o una que no cumple la política, **Then** se rechaza con un mensaje claro.
3. **Given** el usuario cambia su contraseña desde el perfil, **When** la contraseña actual es incorrecta, **Then** se rechaza; **When** es correcta, **Then** se guarda y las demás sesiones del usuario se cierran.

---

### User Story 4 - Administrar roles (Priority: P2)

El Administrador crea roles adicionales (p. ej. "Comandante"), cambia nombre y descripción, y los desactiva cuando no se usen. Los roles precargados no se pueden desactivar.

**Why this priority**: Los tres roles precargados cubren el arranque; crear más es útil pero no bloquea.

**Independent Test**: Crear un rol, darle permisos en la matriz y asignarlo a un usuario.

**Acceptance Scenarios**:

1. **Given** el Administrador, **When** crea el rol "Comandante", **Then** aparece en la matriz sin ningún permiso marcado.
2. **Given** un rol con usuarios activos asignados, **When** se intenta desactivar, **Then** se rechaza indicando cuántos usuarios lo tienen.
3. **Given** un rol inactivo, **When** se crea o edita un usuario, **Then** ese rol no aparece entre las opciones.

---

### User Story 5 - La interfaz respeta los permisos (Priority: P1)

Cada usuario ve en el menú lateral solo los módulos para los que tiene "Ver"; dentro de cada módulo, los botones de crear, editar o desactivar aparecen solo con "Gestionar". Aunque alguien intente la acción por fuera de la interfaz, el sistema la rechaza.

**Why this priority**: Es lo que hace efectiva la matriz para el usuario final y la base de seguridad de los siguientes módulos.

**Independent Test**: Iniciar sesión con Consulta y verificar menú y botones; intentar una modificación directa y comprobar el rechazo.

**Acceptance Scenarios**:

1. **Given** un usuario Consulta, **When** inicia sesión, **Then** ve los módulos con "Ver" y ningún botón de modificación.
2. **Given** un usuario sin "Ver" en Usuarios y roles, **When** navega directamente a esa dirección, **Then** ve un mensaje de acceso denegado.
3. **Given** cualquier usuario sin "Gestionar" en un módulo, **When** intenta una modificación directamente contra el sistema, **Then** se rechaza y no cambia ningún dato.

---

### Edge Cases

- Cambiar los permisos de un rol mientras sus usuarios están conectados: el cambio rige desde la siguiente acción; la interfaz se actualiza al recargar o navegar.
- Dos administradores editan la matriz a la vez: gana el último guardado; no se pierden casillas de otros roles (se guarda por rol, no la matriz completa).
- El Administrador se quita el rol a sí mismo: no se permite si es el único Administrador activo.
- Usuario desactivado intenta iniciar sesión: mensaje genérico de credenciales inválidas (comportamiento actual).
- Correo opcional: un usuario puede no tener correo; si lo tiene, debe ser único.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST definir, por cada módulo de la aplicación, dos permisos: **ver** y **gestionar** (RN-009). Módulos: Activos, Inventario, Entregas, Empleados, Reportes, Configuración, Usuarios y roles. Reportes solo tiene "ver".
- **FR-002**: "Gestionar" MUST implicar "ver" en el mismo módulo.
- **FR-003**: El sistema MUST precargar los roles **Administrador**, **Almacenista** y **Consulta** con estos permisos iniciales (RN-010, RN-011):
  - Administrador: todos los permisos, no modificables.
  - Almacenista: gestionar Activos, Inventario y Entregas; ver Empleados, Reportes, Configuración y Usuarios y roles.
  - Consulta: ver todos los módulos; no gestiona ninguno.
- **FR-004**: El usuario admin existente MUST quedar con el rol Administrador.
- **FR-005**: Los usuarios con "gestionar Usuarios y roles" MUST poder editar la matriz rol × módulo; quienes solo tengan "ver" la consultan en solo lectura.
- **FR-006**: Cada usuario MUST tener exactamente un rol activo asignado (RN-013).
- **FR-007**: El sistema MUST permitir crear, listar, buscar, editar, desactivar y reactivar usuarios; MUST NOT permitir borrarlos.
- **FR-008**: Nombre de usuario y correo MUST ser únicos sin distinguir mayúsculas, se guardan en minúsculas y el nombre de usuario MUST NOT contener "@".
- **FR-009**: Al crear un usuario o restablecer su contraseña, el Administrador MUST asignar una contraseña temporal y el usuario MUST quedar obligado a cambiarla en su siguiente ingreso (RN-014). Mientras tenga el cambio pendiente, solo puede cambiar la contraseña o cerrar sesión.
- **FR-010**: Las contraseñas MUST tener al menos 10 caracteres e incluir letras y números, y la nueva MUST ser distinta de la actual.
- **FR-011**: Desactivar un usuario, restablecer su contraseña o que él la cambie MUST cerrar sus demás sesiones y accesos vigentes.
- **FR-012**: El sistema MUST impedir dejar la aplicación sin al menos un Administrador activo, y MUST impedir que un usuario desactive su propia cuenta.
- **FR-013**: El sistema MUST permitir crear, editar, desactivar y reactivar roles; los roles precargados MUST NOT poder desactivarse, y un rol con usuarios activos MUST NOT poder desactivarse.
- **FR-014**: La información del usuario actual MUST incluir su rol y la lista de permisos efectivos, para que la interfaz adapte menú y acciones.
- **FR-015**: Toda operación de cualquier módulo MUST verificarse contra los permisos del usuario en el servidor, independientemente de lo que muestre la interfaz.
- **FR-016**: Los accesos de larga duración vencidos (dispositivos móviles) MUST depurarse periódicamente.

### Key Entities

- **Usuario**: persona que inicia sesión. Nombre, nombre de usuario, correo opcional, rol, estado activo/inactivo, indicador de cambio de contraseña pendiente, último ingreso. Sin vínculo con Empleado por ahora (RN-015).
- **Rol**: conjunto de permisos. Nombre único, descripción, estado, indicador de rol del sistema (precargado, no desactivable).
- **Permiso**: capacidad "ver" o "gestionar" sobre un módulo. Catálogo fijo definido por la aplicación (no se crean desde la interfaz).
- **Asignación rol–permiso**: las casillas marcadas en la matriz.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un Administrador cambia los permisos de un rol en la matriz en menos de 1 minuto.
- **SC-002**: El 100 % de los intentos de modificación sin permiso "gestionar" se rechaza en el servidor (verificado por pruebas automáticas para cada módulo protegido).
- **SC-003**: Un cambio en la matriz se refleja en la siguiente acción del usuario afectado, sin necesidad de cerrar sesión.
- **SC-004**: El 100 % de los usuarios creados con contraseña temporal debe cambiarla antes de acceder a cualquier módulo.
- **SC-005**: Nunca existe un estado en que la aplicación quede sin Administrador activo.

## Assumptions

- El bloqueo de cuentas por intentos fallidos y la bitácora de autenticación quedan para una feature posterior; se mantiene el límite de intentos por minuto actual.
- La recuperación de contraseña por correo queda fuera hasta definir el servicio de correo (RN-014).
- Los módulos aún no construidos (Activos, Inventario, etc.) ya figuran en la matriz para dejar sus permisos definidos de antemano.
- Política de contraseña: mínimo 10 caracteres con letras y números; sin verificación contra listas de contraseñas filtradas (requeriría un servicio externo).
- No se guarda historial de cambios de la matriz en esta versión.
- El nombre de usuario existente "admin" ya cumple las reglas de formato.
