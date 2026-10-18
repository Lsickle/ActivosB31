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
- ~~¿Mantenimientos o vencimientos?~~ → **Sí, desde el alcance inicial**, con alertas (RN-006, 2026-10-17). Detallado: se programa **por activo individual** (RN-029), anticipación de alerta **configurable, 30 días por defecto** (RN-030) y **fecha de vencimiento opcional** en el activo serializado (RN-031) (2026-10-17).

## 3. Empleados

Personal al que se le asignan activos.

- CRUD de empleados (desactivar, no borrar: conservan su historial de entregas).

Preguntas abiertas:
- ~~Campos~~ → documento (tipo y número, único), nombres, apellidos, cargo/rango (catálogo administrable), compañía/turno, teléfono, correo, fecha de ingreso y tallas de dotación (RN-021, 2026-10-17).
- ~~Datos personales (Ley 1581)~~ → los ve solo quien tenga `empleados.ver` (RN-022, 2026-10-17).

## 4. Entregas

Entrega (y devolución) de activos a empleados.

Preguntas abiertas:
- ~~¿Préstamo o dotación?~~ → **Ambas** (RN-023, 2026-10-17).
- ~~¿Acta o firma?~~ → **Firma en pantalla** del empleado que recibe (RN-024, 2026-10-17).
- ~~¿Varios activos?~~ → **Sí**, serializados y por cantidad en la misma entrega (RN-025, 2026-10-17).
- ~~¿Devuelto dañado o no devuelto?~~ → estado al devolver: bueno / dañado / perdido; préstamos vencidos con alerta (RN-026, 2026-10-17).

## 5. Inventario

Existencias y movimientos.

- Existencias por ubicación.
- Movimientos de entrada, salida, traslado entre ubicaciones y ajuste; los movimientos no se editan ni borran (se corrigen con un movimiento inverso).

Preguntas abiertas:
- ~~¿Conteos físicos?~~ → **Sí, por ubicación**; las diferencias se registran como ajustes (RN-027, 2026-10-17).
- ~~¿Stock mínimo?~~ → **Por tipo de artículo de cantidad**, con alerta (RN-028, 2026-10-17).

## 6. Reportes

- **Reporte por artículo**: historial y estado de un activo o tipo de artículo.
- **Reporte por empleado**: qué tiene asignado o prestado cada empleado, e historial.
- **Inventario general**: existencias por categoría, tipo, ubicación y estado.

Preguntas abiertas:
- ~~Formatos~~ → **Excel y PDF** (RN-032, 2026-10-17).
- ~~¿Rango de fechas?~~ → **Sí** (RN-033, 2026-10-17).

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
- **RN-016** — El conteo de usuarios de un rol considera **solo usuarios activos** (el mismo criterio que impide desactivar un rol en uso). (usuario, 2026-10-17)
- **RN-017** — Un usuario cuyo rol fue desactivado **puede editarse conservando ese rol**; lo que no se permite es asignar un rol inactivo a un usuario que no lo tenía. (usuario, 2026-10-17)
- **RN-018** — Un usuario **no puede restablecer su propia contraseña** desde la gestión de usuarios; debe usar "Cambiar contraseña" (con su contraseña actual). (usuario, 2026-10-17)
- **RN-019** — El permiso **gestionar Usuarios y roles** (usuarios, roles, matriz y contraseñas de otros) es **exclusivo del rol Administrador**: no se puede conceder a otros roles desde la matriz. (usuario, 2026-10-17, a partir del hallazgo H-1 de la revisión de seguridad)
- **RN-020** — Los roles precargados (Administrador, Almacenista, Consulta) **no se pueden renombrar**; solo se edita su descripción. (usuario, 2026-10-17)

- **RN-021** — Datos del empleado: documento (tipo y número, **único**), nombres, apellidos, cargo/rango (catálogo administrable), compañía o turno, teléfono, correo, fecha de ingreso y **tallas de dotación** (camisa, pantalón, botas, guantes). Los empleados no se borran, se desactivan. (usuario, 2026-10-17)
- **RN-022** — Los datos personales de los empleados (Ley 1581 de 2012) los ve **solo quien tenga `empleados.ver`**; inicialmente Administrador y Almacenista. (usuario, 2026-10-17)
- **RN-023** — Una entrega puede ser **dotación** (asignación permanente) o **préstamo** con fecha de devolución esperada. (usuario, 2026-10-17)
- **RN-024** — Toda entrega lleva la **firma en pantalla** del empleado que recibe. (usuario, 2026-10-17)
- **RN-025** — Una entrega puede incluir **varios artículos**, serializados y por cantidad. (usuario, 2026-10-17)
- **RN-026** — Al devolver se registra el estado: **bueno** (vuelve a Disponible), **dañado** (pasa a En mantenimiento) o **perdido** (se da de baja con observación). Los préstamos vencidos generan **alerta**. (usuario, 2026-10-17)
- **RN-027** — Se hacen **conteos físicos por ubicación**; las diferencias se registran como **movimientos de ajuste**. (usuario, 2026-10-17)
- **RN-028** — **Stock mínimo por tipo de artículo** controlado por cantidad, con alerta cuando las existencias totales quedan por debajo. (usuario, 2026-10-17)
- **RN-029** — Los mantenimientos se programan **por activo individual** (tipo de mantenimiento, periodicidad y fecha del último realizado; el sistema calcula el siguiente). (usuario, 2026-10-17)
- **RN-030** — La **anticipación de las alertas** de mantenimiento y vencimiento es **configurable, 30 días por defecto**. (usuario, 2026-10-17)
- **RN-031** — Los activos serializados tienen **fecha de vencimiento opcional** (vida útil), con la misma alerta. (usuario, 2026-10-17)
- **RN-032** — Los reportes se exportan en **Excel y PDF**. (usuario, 2026-10-17)
- **RN-033** — Los reportes admiten **filtro por rango de fechas**. (usuario, 2026-10-17)
- **RN-034** — **Bloqueo de cuenta**: 5 intentos fallidos seguidos bloquean la cuenta 15 minutos; el Administrador puede desbloquearla. (usuario, 2026-10-17)

- **RN-035** — Cargos/rangos precargados (editables): **Comandante, Capitán, Teniente, Subteniente, Sargento, Cabo, Bombero, Administrativo**. (usuario, 2026-10-17)
- **RN-036** — Las **compañías/turnos** son un catálogo administrable en Configuración, sin precarga. (usuario, 2026-10-17)
- **RN-037** — En un conteo físico, el activo serializado no encontrado **solo se reporta**; su estado no cambia automáticamente. (usuario, 2026-10-17)
- **RN-038** — Los artículos por cantidad devueltos **dañados o perdidos no vuelven al stock**: se registran como pérdida. (usuario, 2026-10-17)
- **RN-039** — Al entregar un activo serializado pasa a **Asignado**, registra **quién lo tiene** y **conserva su última ubicación**. (usuario, 2026-10-17)
- **RN-040** — El rol **Consulta no tiene `empleados.ver`** en la precarga (RN-022); se puede conceder desde la matriz. (usuario, 2026-10-17)

Decisiones de infraestructura (usuario, 2026-10-17; se verifica vigencia con `stack-advisor` antes de fijar): archivos (fotos, firmas) en **Cloudflare R2**; base de datos de producción en **Neon** con **copia diaria (`pg_dump`) a R2**; las fotos se **re-codifican al subirlas** (sin EXIF/GPS, ~1600 px). Detalle en `docs/infraestructura.md`.

Riesgos aceptados por el usuario (2026-10-17): Almacenista y Consulta conservan `usuarios.ver` con los datos completos de las cuentas (hallazgo M-4, Ley 1581); se puede retirar desde la matriz. La bitácora de seguridad (A-1) se hará como feature aparte **antes de cargar datos reales**, junto con el bloqueo por intentos fallidos.


---

## Preguntas pendientes del usuario (2026-10-17)

Formuladas en la conversación con opciones; ⭐ = recomendada. Lo marcado "provisional" ya está implementado con la opción recomendada y se ajusta si el usuario elige otra.

| # | Pregunta | Recomendada | Estado |
|---|---|---|---|
| 26 | Retención de la bitácora de seguridad | ⭐ login fallidos de cuentas inexistentes 90 días; resto 2 años (ajustable a TRD) | pendiente |
| 27 | Protección de la cuenta admin | ⭐ usuario/correo del admin inicial desde variables de entorno + segundo Administrador al desplegar | pendiente |
| 28 | Naturaleza jurídica de la estación (RNBD) | — (entidad pública / bomberos voluntarios / no sabe) | pendiente |
| 29 | Datos de empleados retirados | ⭐ conservar nombre y documento; borrar contacto y tallas tras 2 años inactivo | pendiente |
| 30 | Auditoría de cambios de empleados + copia del empleado en cada entrega | ⭐ sí (pequeño cambio de esquema) | pendiente |
| 31 | Formato del documento por tipo | ⭐ CC/TI/CE solo dígitos 5–12; PA/PPT alfanumérico | pendiente |
| 32 | Consecutivo de entregas sin huecos | ⭐ no importa que haya saltos | pendiente |
| 33 | Quién ve la imagen de la firma | ⭐ entregas.gestionar o empleados.ver | **provisional 33A implementada** |
| 34 | Plazo máximo de un préstamo | ⭐ 1 año | pendiente |
| 35 | Activo serializado debe estar en la ubicación de origen | ⭐ sí, el asistente solo ofrece los del origen | pendiente |
| 36 | `reportes.ver` sin permiso del módulo de origen | ⭐ no: cada reporte exige también el ver de su módulo | pendiente |
| 37 | Plan de Render para el backend | ⭐ Starter (~US$7/mes): necesario para el paso previo de migraciones y la consola (desbloqueo del admin); Free se apaga a los 15 min | pendiente |
| 38 | Tareas programadas en Render | ⭐ 2 Cron Jobs (≥ US$1/mes c/u): limpieza diaria + respaldo diario a R2 | pendiente |
| 39 | Peticiones al backend que no pasan por Vercel | ⭐ aceptarlas (para el futuro cliente móvil) usando la IP que ve Render; B: rechazarlas con 403 | pendiente (por defecto: aceptar) |
| 40 | Cifrar los respaldos | ⭐ sí, con clave guardada fuera de Render (Ley 1581) | pendiente |
| 41 | Permisos de BD por columna como migración automática | ⭐ sí, condicionada a producción (`DB_APP_ROLE`) | pendiente |
| 42 | Cómo se ejecutan las migraciones en producción (credencial dueña de la BD) | ⭐ desde GitHub Actions con aprobación manual y luego Deploy Hook de Render (la credencial dueña nunca vive en Render); B: variable temporal en Render solo durante deploys con migraciones | pendiente |
