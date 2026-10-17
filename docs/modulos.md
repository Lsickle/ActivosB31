# Módulos de ActivosB31 — alcance inicial

Borrador de alcance a partir de la descripción del usuario (2026-10-17) y del prototipo de v0 (`docs/prototipo-v0/`, exportado como "DotaControl": app genérica de dotación; es referencia visual, no define reglas). **Las preguntas abiertas no se resuelven en código sin respuesta del usuario**; al resolverlas, se anota aquí la respuesta y la fecha, y se registran como reglas `RN-XXX` en la sección final.

Contexto: aplicación de una sola organización — la Estación de Bomberos de Madrid, Cundinamarca.

---

## 1. Usuarios, roles y permisos

Quién entra al sistema y qué puede hacer.

- CRUD de usuarios del sistema (activar/desactivar, no borrar).
- Roles con permisos por módulo y acción (`<modulo>.<accion>`, p. ej. `activos.crear`).
- El backend ya tiene login (cookie web / token Bearer), logout, `GET /api/user` y bloqueo de cuentas inactivas. `GET /api/user` devuelve `permissions: []` hasta implementar este módulo.

Preguntas abiertas:
- ¿Qué roles hay? (p. ej. Administrador, Almacenista/Encargado de inventario, Comandante/Consulta).
- ¿Un usuario del sistema es siempre un empleado? ¿Los empleados sin usuario solo reciben entregas?
- Política de contraseñas, bloqueo por intentos fallidos y recuperación de contraseña por correo.

## 2. Activos

Equipos, herramientas, EPP, vehículos y demás bienes de la estación.

- Registro de activos con categoría → tipo (ver Configuración), ubicación, estado.
- Historial por activo (entregas, movimientos, cambios de estado, baja).

Preguntas abiertas:
- ~~¿Serializados o por cantidad?~~ → **Ambos** (RN-001, 2026-10-17).
- Campos obligatorios: ¿placa de inventario, serial, marca, modelo, fecha de adquisición, valor, vida útil, foto?
- Estados del activo: ¿disponible, asignado, en mantenimiento, dañado, dado de baja?
- ¿Se requieren mantenimientos o vencimientos (p. ej. prueba hidrostática de cilindros, vencimiento de EPP)?

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
- ~~¿Ubicaciones jerárquicas?~~ → **Lista plana, cada ubicación con un tipo** (RN-002, 2026-10-17). Pendiente: ¿los tipos de ubicación son una lista fija o un catálogo administrable?
- ~~¿Desactivar/borrar categorías con datos?~~ → **Solo desactivar** (RN-003, 2026-10-17).

---

## Reglas de negocio confirmadas

Formato: `RN-XXX — enunciado (fuente, fecha)`.

- **RN-001** — La estación controla dos naturalezas de activo: **serializados** (cada unidad identificable con placa/serial: cilindros, equipos, vehículos) y **por cantidad** (artículos con existencias: guantes, uniformes, consumibles). (usuario, 2026-10-17)
- **RN-002** — Las ubicaciones son una **lista plana** (sin jerarquía); cada ubicación tiene un **tipo** (p. ej. bodega, vehículo, oficina). (usuario, 2026-10-17)
- **RN-003** — Categorías y tipos de activo **no se borran** si tienen datos asociados; solo se **desactivan**. Una categoría inactiva oculta sus tipos para registros nuevos, pero se conserva el historial. (usuario, 2026-10-17)
