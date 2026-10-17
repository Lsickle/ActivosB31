# Research: 001-rbac

No quedaron `NEEDS CLARIFICATION` en el contexto técnico: el stack está fijado en `CLAUDE.md` y las decisiones de negocio en RN-009 a RN-015. Esta fase documenta las decisiones de diseño.

## D-001 — RBAC con tablas propias, sin paquete externo

- **Decision**: tablas `roles`, `permissions`, `role_permissions` y `users.role_id`; autorización con `Gate`/Policies nativas de Laravel.
- **Rationale**: el modelo es pequeño (13 permisos fijos, un rol por usuario); es el patrón ya probado en EcoLink-Platform; evita agregar una dependencia (regla 4 de `CLAUDE.md`).
- **Alternatives considered**: `spatie/laravel-permission` (pensado para múltiples roles y permisos directos al usuario; más tablas y caché propia que no se necesitan).

## D-002 — Catálogo de permisos en un enum PHP

- **Decision**: `App\Enums\Permission` (backed enum con `module()`, `action()`, `label()`); el seeder sincroniza la tabla `permissions` desde el enum.
- **Rationale**: los permisos no se crean desde la UI (son código); el enum da autocompletado y evita strings sueltos en Policies y tests.
- **Alternatives considered**: solo strings en config (sin tipado); solo tabla (permite drift entre código y BD).

## D-003 — Evaluación de permisos

- **Decision**: `Gate::before` concede todo al rol `admin`; para el resto, `Gate::define` por cada caso del enum que consulta `User::hasPermission()`. Los permisos del usuario se cargan una vez por request (`role.permissions` memoizado en el modelo). Las rutas usan `->can('<codigo>')` o Policies (`UserPolicy`, `RolePolicy`) cuando hay reglas sobre el registro (último admin, uno mismo).
- **Rationale**: cumple SC-003 (cambio efectivo en la siguiente request) sin caché que invalidar; una consulta adicional por request es despreciable a esta escala.
- **Alternatives considered**: caché de permisos por rol (requiere invalidación al editar la matriz; innecesario).
- **Referencia EcoLink**: allí no hay `Gate::before` ni memoización: cada `hasPermission()` es una consulta con joins, y los controladores llaman a `Gate::authorize(...)` contra Policies auto-descubiertas. Se adopta lo segundo (Policies + `Gate::authorize` en controladores) y se agregan las dos mejoras señaladas: `Gate::before` para `admin` y memoización por request. Se omite lo multi-tenant y los pivotes con `expires_at`/`is_active`/softDeletes (no hay requisito).

## D-010 — Guardado de la matriz

- **Decision**: `PUT /api/roles/{role}/permissions` reemplaza el conjunto completo de un rol.
- **Rationale**: la UI ya conoce el estado del rol; un solo request por rol es atómico y normaliza `gestionar ⇒ ver` en el servidor.
- **Alternatives considered**: assign/revoke por casilla como EcoLink (`POST permissions/{p}/assign|revoke`): más requests y la regla "gestionar implica ver" quedaría repartida entre dos llamadas.

## D-011 — Helpers de test

- **Decision**: en `tests/Pest.php`, `actingAsRole('consulta')` (usa roles sembrados) y `actingWithPermissions([...])` (crea un rol ad hoc con esos permisos), siguiendo `actorWithSeededRoles` de EcoLink; `RefreshDatabase` global se mantiene.

## D-004 — Cambio de contraseña obligatorio

- **Decision**: middleware `password.changed` (`EnsurePasswordIsChanged`) agregado al grupo autenticado; excluye `GET /api/user`, `PUT /api/user/password` y `POST /api/logout`. Responde `403` con `code: password_change_required`. El frontend redirige a `/cambiar-contrasena` cuando `user.must_change_password` es `true`.
- **Rationale**: el servidor hace cumplir RN-014 aunque se use la API directamente.

## D-005 — Revocación de sesiones y tokens (FR-011)

- **Decision**: servicio `RevokeUserSessions` que borra filas de `sessions` por `user_id` (driver `database`, ya configurado) y los `personal_access_tokens` del usuario; en cambio propio, conserva la sesión actual (`session()->getId()`) y regenera su id.
- **Rationale**: el driver de sesión es `database`, así que la revocación es una consulta directa; el middleware `active` ya cubre el caso de desactivación como segunda barrera.

## D-006 — Unicidad sin mayúsculas y normalización

- **Decision**: migración que reemplaza los índices únicos de `username`/`email` por índices de expresión `lower(...)` (email parcial `WHERE email IS NOT NULL`), normaliza a minúsculas en un mutator del modelo y valida con `Rule::unique` sobre `lower()`.
- **Rationale**: pendiente de seguridad nº 3 de `CLAUDE.md`; el login ya compara en minúsculas.

## D-007 — Invariante "al menos un Administrador activo"

- **Decision**: verificación en una acción transaccional con `lockForUpdate()` sobre los usuarios admin activos antes de desactivar o cambiar de rol; responde `409`.
- **Rationale**: evita la condición de carrera de dos admins desactivándose mutuamente a la vez.

## D-008 — Frontend

- **Decision**: ruta `/usuarios` con pestañas (Usuarios, Roles, Permisos) usando TanStack Table para listados; la matriz es una tabla de checkboxes por rol con guardado por rol. Hook `useCan(permiso)` en `packages/app/provider/auth`; `navItems` declara `permission` (`<modulo>.ver`) y el sidebar filtra. Nueva ruta `/cambiar-contrasena` fuera del layout del tablero.
- **Rationale**: reutiliza `useRequireAuth(requiredPermission)` ya existente y la estructura `features/<modulo>` de `CLAUDE.md`.

## D-009 — Depuración de tokens vencidos (FR-016)

- **Decision**: `Schedule::command('sanctum:prune-expired --hours=24')->daily()` en `routes/console.php`.
- **Rationale**: pendiente de seguridad nº 3; comando nativo de Sanctum. En Render el scheduler requiere un cron job o worker (se anota para el despliegue).
