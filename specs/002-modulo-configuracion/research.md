# Research: 002-modulo-configuracion

Decisiones técnicas. Ninguna cambia reglas de negocio; las que podrían hacerlo están marcadas.

## D-001 — Una Policy base para los cuatro catálogos

- **Decision**: `ConfigurationCatalogPolicy` abstracta con `viewAny`/`view` → `configuracion.ver` y `create`/`update`/`updateStatus` → `configuracion.gestionar`. Cuatro subclases vacías (`AssetCategoryPolicy`, …) para que Laravel las descubra por convención. Autorización en `authorize()` de cada Form Request, como en 001.
- **Rationale**: las cuatro entidades comparten exactamente las mismas reglas (RN-011); sin `Gate::before` (M-5 de 001).
- **Alternatives**: `Gate::policy()` manual para una sola clase (menos explícito); middleware `can:` en rutas (rompe el patrón de 001).

## D-002 — Normalización de nombres

- **Decision**: `TrimStrings` (middleware global) ya recorta espacios; además se colapsan espacios internos repetidos en `prepareForValidation`. Se conserva la capitalización que escriba el usuario.
- **Rationale**: Edge case de la spec ("se eliminan espacios al inicio y al final antes de validar unicidad").
- **Alternatives**: guardar en minúsculas (como username) — no aplica: son nombres visibles.

## D-003 — Búsqueda sin mayúsculas ni tildes

- **Decision**: `translate(lower(name), 'áéíóúüñ', 'aeiouun') LIKE translate(lower(?), …)` con comodines escapados, en un concern `HasCatalogSearch` (scope `search()`). Busca en `name` (y en el nombre de la categoría/tipo padre no).
- **Rationale**: la spec pide ignorar tildes "en lo posible"; `translate()` es nativa, no requiere `CREATE EXTENSION unaccent` (permisos en el Postgres administrado). Volumen bajo: el escaneo secuencial sobre cientos de filas cumple SC-004.
- **Alternatives**: extensión `unaccent` + índice funcional (más infraestructura); `ilike` simple (no ignora tildes).

## D-004 — Paginación y orden

- **Decision**: listados paginados `per_page` por defecto 25, máximo 100; orden `name` asc (tipos de activo: nombre; empate por id). Filtros: `search`, `is_active`; tipos de activo además `asset_category_id` y `tracking_mode`; ubicaciones `location_type_id`.
- **Rationale**: FR-013; mismo formato `{data, meta, links}` que `GET /api/users`.

## D-005 — Opciones disponibles para otros módulos (FR-010) ⚠️ revisar con Activos

- **Decision**: `GET /api/configuracion/options` devuelve en una sola respuesta, sin paginar, las categorías activas, los tipos activos de categorías activas (con `tracking_mode` y `asset_category_id`), los tipos de ubicación activos y las ubicaciones activas de tipos activos. Requiere `configuracion.ver` por ahora.
- **Rationale**: un solo request para los formularios de Activos/Inventario; los formularios de Configuración también lo usan para los selects (categorías activas, tipos de ubicación activos — FR-007).
- **Pendiente**: cuando exista Activos, decidir con el usuario si quien tiene `activos.gestionar` sin `configuracion.ver` debe poder leer las opciones. Hoy todos los roles tienen `configuracion.ver` (RN-011), así que no hay impacto.
- **Resuelto 2026-10-17** (feature 004): las opciones las lee cualquier usuario autenticado y activo con al menos un permiso `*.ver` de cualquier módulo (`ConfigurationCatalogPolicy::viewOptions`); solo un usuario sin ningún permiso recibe 403. Son nombres de catálogo, no datos sensibles (RN-011: "los catálogos los consulta cualquier rol"), y así un rol con `empleados.gestionar` o `activos.gestionar` sin `configuracion.ver` puede llenar sus formularios. La respuesta incluye también `positions` y `companies` activos (004, D-001). Los listados y detalles de cada catálogo siguen exigiendo `configuracion.ver`.
- **Alternatives**: `?available=1` en cada listado (cuatro requests, mezcla dos contratos).

## D-006 — Activar/desactivar

- **Decision**: `PATCH /api/<catalogo>/{id}/status` con `{is_active}`, como usuarios y roles. Sin cascada (FR-008). Sin 409: ningún catálogo tiene invariantes que impidan desactivar en esta versión.
- **Rationale**: RN-003; consistencia con 001. Desactivar una categoría con tipos activos es válido: los tipos quedan "no disponibles" por la regla de `available()`.

## D-007 — Unicidad por categoría en tipos de activo

- **Decision**: `UniqueCaseInsensitive` recibe un alcance opcional (`['asset_category_id' => $id]`). Índice `UNIQUE(asset_category_id, lower(name))` en BD; violación en carrera → 422 vía `TranslatesUniqueViolations`.
- **Rationale**: FR-006; reutiliza la regla y el trait de 001.

## D-008 — Precarga idempotente que respeta ediciones (RN-012)

- **Decision**: `ConfigurationSeeder` carga categorías + tipos solo si `asset_categories` está vacía, y tipos de ubicación solo si `location_types` está vacía, en transacción. Se llama desde `DatabaseSeeder` en todos los entornos.
- **Rationale**: Render ejecuta `migrate --seed` en cada arranque. Un `firstOrCreate` por nombre volvería a crear una categoría que el administrador renombró, y como no se borra (RN-003) quedaría un duplicado imposible de quitar. "Solo si está vacía" cumple FR-014 (repetir no duplica) sin pisar ediciones.
- **Alternatives**: `firstOrCreate` por `lower(name)` (recrea renombrados); columna `code` de precarga (cambia el esquema aprobado).

## D-009 — Modo de control inmutable con activos (FR-009)

- **Decision**: `AssetType::hasAssets(): bool` devuelve `false` hasta que exista la tabla de activos; `UpdateAssetTypeRequest` rechaza el cambio de `tracking_mode` con 422 cuando devuelve `true`. Test que fija el comportamiento con un doble (partial mock) para que el contrato quede probado.
- **Rationale**: spec Assumptions: "queda preparada pero sin efecto".

## D-010 — Integridad referencial

- **Decision**: `asset_types.asset_category_id` y `locations.location_type_id` `NOT NULL`, `constrained()->restrictOnDelete()`, con índice. Longitudes `name varchar(120)`, `description varchar(500)`; `tracking_mode varchar(20) CHECK`.
- **Rationale**: no hay borrado (RN-003); `restrict` lo garantiza también a nivel de BD.

## Frontend

- Una pantalla `ConfiguracionScreen` con pestañas (Categorías, Tipos de activo, Tipos de ubicación, Ubicaciones), igual que `UsuariosScreen`. Pestaña por defecto: Categorías; se recuerda en la URL (`?tab=`) si el patrón de 001 ya lo hace, si no, estado local.
- Sin `configuracion.gestionar`: no se muestran "Nuevo", "Editar" ni "Activar/Desactivar" (FR-011); el servidor igual responde 403.
- Selects de categoría / tipo de ubicación en formularios: desde `GET /api/configuracion/options`. Al editar un registro cuyo padre está inactivo, el select muestra el padre actual (deshabilitado como opción, marcado "inactivo") para no forzar el cambio (edge case de la spec).
- Pestaña Tipos de activo: columna Categoría con insignia "Categoría inactiva" cuando corresponda (US2-4); columna Modo ("Serializado" / "Por cantidad").
- Ubicaciones sin tipos activos: el botón "Nueva ubicación" abre un aviso "Primero crea un tipo de ubicación activo" (US3-1).
