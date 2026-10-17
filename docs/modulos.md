# Módulos de ActivosB31 — alcance inicial

Borrador de alcance a partir de la descripción del usuario (2026-10-17) y del prototipo de v0 (`docs/prototipo-v0/`, exportado como "DotaControl": app genérica de dotación; es referencia visual, no define reglas). **Las preguntas abiertas no se resuelven en código sin respuesta del usuario**; al resolverlas, se anota aquí la respuesta y la fecha, y se registran como reglas `RN-XXX` en la sección final.

Contexto: aplicación de una sola organización — la Estación de Bomberos de Madrid, Cundinamarca.

---

## 1. Usuarios, roles y permisos

Quién entra al sistema y qué puede hacer.

- CRUD de usuarios del sistema (activar/desactivar, no borrar).
- Roles con permisos por módulo en dos niveles: `<modulo>.ver` y `<modulo>.gestionar` (RN-009).
- El backend ya tiene login (cookie web / token Bearer), logout, `GET /api/user` y bloqueo de cuentas inactivas. `GET /api/user` devuelve `permissions: []` hasta implementar este módulo.

Preguntas abiertas:
- ~~¿Qué roles hay?~~ → Precargados **Administrador, Almacenista y Consulta**; se pueden crear más desde la matriz (RN-010, 2026-10-17).
- ~~Granularidad de permisos~~ → **Ver / Gestionar** por módulo, administrados en una **matriz rol × módulo** (RN-009, 2026-10-17).
- ~~¿Usuario = empleado?~~ → Independientes por ahora; vínculo **opcional** usuario → empleado cuando exista Empleados (RN-015, 2026-10-17).
- ~~Contraseñas~~ → **temporal asignada por el admin + cambio obligatorio** al primer ingreso; recuperación por correo más adelante (RN-014, 2026-10-17). Pendiente: bloqueo por intentos fallidos.

## 2. Activos

Equipos, herramientas, EPP, vehículos y demás bienes de la estación.

- Registro de activos con categoría → tipo (ver Configuración), ubicación, estado.
- Historial por activo (entregas, movimientos, cambios de estado, baja).

Preguntas abiertas:
- ~~¿Serializados o por cantidad?~~ → **Ambos** (RN-001, 2026-10-17).
- Campos del activo serializado: confirmados **marca, modelo, fecha de adquisición, valor (vida útil opcional) y foto** (2026-10-17). Placa y serial **solo aplican a los serializados**; los artículos por cantidad (guantes, etc.) no los llevan (usuario, 2026-10-17). La naturaleza la define el **tipo de activo** (RN-007) y en los serializados la placa es obligatoria y única, el serial opcional (RN-008). La foto depende de decidir el almacenamiento de archivos.
- ~~Estados del activo~~ → **Disponible, Asignado, En mantenimiento, Dado de baja** (RN-005, 2026-10-17).
- ~~¿Mantenimientos o vencimientos?~~ → **Sí, desde el alcance inicial**, con alertas (RN-006, 2026-10-17). Pendiente detallar: qué se programa (por tipo de activo o por activo), periodicidad y anticipación de la alerta.

## 3. Empleados

Personal al que se le asignan activos.

- CRUD de empleados (desactivar, no borrar: conservan su historial de entregas).

Preguntas abiertas:
- Campos: ¿documento, nombre, cargo/rango, turno o compañía, teléfono, correo, tallas de EPP?
- Datos personales bajo Ley 1581 de 2012: ¿quién puede verlos?

## 4. Entregas

Entrega (y devolución) de activos a empleados.

Preguntas abiertas:
- ¿Una entrega es un **préstamo con devolución** esperada, una **asignación permanente** (dotación), o ambas?
- ¿Se requiere acta o firma del empleado que recibe (en pantalla, PDF imprimible)?
- ¿Una entrega puede incluir varios activos a la vez?
- ¿Qué pasa si se devuelve dañado o no se devuelve?

## 5. Inventario

Existencias y movimientos.

- Existencias por ubicación.
- Movimientos de entrada, salida, traslado entre ubicaciones y ajuste; los movimientos no se editan ni borran (se corrigen con un movimiento inverso).

Preguntas abiertas:
- ¿Se hacen conteos físicos periódicos y se concilian contra el sistema?
- ¿Hay stock mínimo con alerta?

## 6. Reportes

- **Reporte por artículo**: historial y estado de un activo o tipo de artículo.
- **Reporte por empleado**: qué tiene asignado o prestado cada empleado, e historial.
- **Inventario general**: existencias por categoría, tipo, ubicación y estado.

Preguntas abiertas:
- Formatos de exportación: ¿PDF, Excel o ambos?
- ¿Filtros por rango de fechas?

## 7. Configuración

Parametrizaciones administrables desde la aplicación:

- **Categorías de activos**.
- **Tipos de activo**: cada tipo pertenece a **una** categoría; una categoría tiene **muchos** tipos (relación 1:N, requisito explícito del usuario).
- **Ubicaciones**.

Preguntas abiertas:
- ~~¿Ubicaciones jerárquicas?~~ → **Lista plana, cada ubicación con un tipo** (RN-002, 2026-10-17). Tipos de ubicación: **catálogo administrable** (RN-004, 2026-10-17).
- ~~¿Desactivar/borrar categorías con datos?~~ → **Solo desactivar** (RN-003, 2026-10-17).

---

## Reglas de negocio confirmadas

Formato: `RN-XXX — enunciado (fuente, fecha)`.

- **RN-001** — La estación controla dos naturalezas de activo: **serializados** (cada unidad identificable con placa/serial: cilindros, equipos, vehículos) y **por cantidad** (artículos con existencias: guantes, uniformes, consumibles). (usuario, 2026-10-17)
- **RN-002** — Las ubicaciones son una **lista plana** (sin jerarquía); cada ubicación tiene un **tipo** (p. ej. bodega, vehículo, oficina). (usuario, 2026-10-17)
- **RN-003** — Categorías y tipos de activo **no se borran** si tienen datos asociados; solo se **desactivan**. Una categoría inactiva oculta sus tipos para registros nuevos, pero se conserva el historial. (usuario, 2026-10-17)
- **RN-004** — Los tipos de ubicación son un **catálogo administrable** desde Configuración (crear, editar, desactivar). (usuario, 2026-10-17)
- **RN-005** — Estados de un activo serializado: **Disponible, Asignado, En mantenimiento, Dado de baja**. (usuario, 2026-10-17)
- **RN-006** — El sistema controla **mantenimientos y vencimientos** desde el alcance inicial (p. ej. prueba hidrostática, vencimiento de EPP), con alertas de próximos a vencer. (usuario, 2026-10-17)
- **RN-007** — La naturaleza de control (**serializado** o **por cantidad**) la define el **tipo de activo**; todos los artículos de un tipo siguen esa regla. (usuario, 2026-10-17)
- **RN-008** — En los activos serializados la **placa de inventario es obligatoria y única**; el **serial del fabricante es opcional**. Los artículos por cantidad no llevan placa ni serial. (usuario, 2026-10-17)
- **RN-009** — Los permisos se definen **por módulo en dos niveles**: **ver** (consultar) y **gestionar** (crear, editar, desactivar). Se administran desde una **matriz rol × módulo** en la aplicación. (usuario, 2026-10-17)
- **RN-010** — Roles precargados: **Administrador** (todos los permisos, incluida la matriz; sus permisos no se pueden quitar), **Almacenista** (gestiona activos, inventario y entregas; ve configuración y usuarios) y **Consulta** (solo ver). Sus permisos iniciales se ajustan luego desde la matriz, salvo los del Administrador. (usuario, 2026-10-17)
- **RN-011** — Los catálogos de Configuración los **consulta cualquier rol**; **solo el Administrador los modifica** (permiso inicial de la matriz). (usuario, 2026-10-17)
- **RN-012** — Los catálogos de Configuración se **precargan** con categorías, tipos de activo y tipos de ubicación iniciales; la lista exacta se confirma con el usuario. (usuario, 2026-10-17)
- **RN-013** — Cada usuario tiene **exactamente un rol**. (usuario, 2026-10-17)
- **RN-014** — El Administrador asigna una **contraseña temporal** al crear un usuario o al restablecerla; el usuario **debe cambiarla** en su siguiente ingreso. La recuperación por correo queda para cuando se defina el servicio de correo. (usuario, 2026-10-17)
- **RN-015** — Usuarios y empleados son independientes en esta etapa; cuando exista el módulo Empleados se agrega un **vínculo opcional** usuario → empleado. (usuario, 2026-10-17)
