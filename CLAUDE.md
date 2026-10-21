# CLAUDE.md — ActivosB31

Contexto de proyecto para Claude Code. **Responde siempre en español.**

## Qué es ActivosB31

Aplicación web para la gestión de inventario y activos de la **Estación de Bomberos de Madrid, Cundinamarca** (Colombia). **Una sola organización** (no es multi-tenant). Módulos:

1. Usuarios, roles y permisos
2. Activos
3. Empleados
4. Entregas
5. Inventario
6. Reportes: por artículo, por empleado e inventario general
7. Configuración: categorías de activos, tipos de activo (cada tipo pertenece a una categoría, relación 1:N) y ubicaciones

Alcance detallado y **preguntas abiertas** de cada módulo: `docs/modulos.md`. Prototipo visual (v0 de Vercel): `docs/prototipo-v0/`.

La arquitectura y el stack replican los de **EcoLink-Platform** (`C:\Programar\EcoLink-Platform`), un proyecto hermano del mismo autor. Se puede consultar como referencia de patrones ya resueltos (RBAC con tablas propias, Policies, pantallas CRUD de catálogos, tablas con TanStack), pero **no se copia lógica de negocio de EcoLink** (residuos, RESPEL, multi-tenant).

---

## Stack (fijado al inicializar, 2026-10-17)

| Capa | Tecnología |
|---|---|
| Backend | Laravel 13 · PHP 8.4 · Sanctum 4 (cookie de sesión en web, token Bearer para un futuro cliente móvil) |
| Base de datos | PostgreSQL 17 |
| Cache / colas | Driver `database` por ahora; Redis disponible en el stack local |
| Tests backend | Pest 4 (en paralelo), Pint para formato |
| Frontend web | Next.js 16 · React 19 · Solito 5 (monorepo Yarn 4 + turbo) |
| UI | Tailwind CSS v4 · shadcn/ui estilo `base-nova` sobre **Base UI** (prop `render`, no `asChild`) · lucide-react · sonner · TanStack Table · recharts · zod |
| Tests frontend | Vitest + Testing Library |
| Dev local | Laravel Sail (Docker) |
| CI | GitHub Actions (`.github/workflows/ci.yml`) |
| Despliegue | Backend en **Render** (`backend/Dockerfile.render`), frontend en **Vercel** |
| Móvil | Sin app todavía. `frontend/packages/app` queda listo para agregar Expo después. |

Pendiente de decidir con el usuario (no fijar sin confirmar): base de datos de producción (Render PostgreSQL u otro proveedor), almacenamiento de archivos (fotos de activos, actas), envío de correo, formato de exportación de reportes (PDF/Excel).

### Cuentas de servicios externos

GitHub, Render, Vercel y demás servicios se conectarán con una **cuenta distinta** a la del autor de EcoLink. Todavía no hay nada conectado: el repo git está inicializado **sin commits y sin remote**. Antes del primer commit:

```bash
git config user.name  "<nombre de la cuenta nueva>"
git config user.email "<correo de la cuenta nueva>"
```

(configuración **local del repo**, no `--global`). No reutilices credenciales, tokens ni CLIs autenticados con la cuenta de EcoLink. Si `gh`/`vercel` están logueados con otra cuenta, pide al usuario que cambie de sesión antes de crear el repo o el proyecto.

---

## Entorno local

Puertos elegidos para no chocar con EcoLink-Platform (ambos stacks pueden correr a la vez):

| Servicio | URL / puerto |
|---|---|
| API Laravel | http://localhost:8081 (`/up` = health check) |
| Frontend Next.js | http://localhost:3001 |
| PostgreSQL | localhost:5433 (usuario `sail` / `password`, BD `activosb31`; los tests usan la BD `testing`) |
| Redis | localhost:6380 |
| Mailpit | http://localhost:8026 |

### Backend

El proyecto Docker Compose se llama `activosb31` (`COMPOSE_PROJECT_NAME` en `backend/.env`).

**En Windows con Git Bash `vendor/bin/sail` no funciona** ("Unsupported operating system"). Usa `docker compose` desde `backend/` (es lo que Sail hace por debajo):

```bash
cd backend
cp .env.example .env            # solo la primera vez; luego definir ADMIN_INITIAL_PASSWORD
WWWUSER=1000 WWWGROUP=1000 docker compose up -d
docker compose exec laravel.test php artisan key:generate   # solo la primera vez
docker compose exec laravel.test php artisan migrate --seed
docker compose exec laravel.test composer test              # Pest en paralelo
docker compose exec laravel.test vendor/bin/pint --dirty
```

Si los comandos que escriben archivos fallan por permisos dentro del contenedor, ejecútalos como root (`docker compose exec -u root …`) o corre `chmod -R 777 storage bootstrap/cache` dentro del contenedor.

El seeder crea el usuario `admin` (correo `admin@activosb31.local`) con la contraseña de `ADMIN_INITIAL_PASSWORD`; si está vacía, genera una y la imprime una sola vez.

### Frontend

```bash
cd frontend
yarn install          # Yarn 4.7 viene en .yarn/releases (si no hay yarn global: node .yarn/releases/yarn-4.7.0.cjs install)
cp apps/next/.env.example apps/next/.env.local
yarn dev              # http://localhost:3001
yarn test             # Vitest (incluye packages/app)
yarn typecheck
```

### Alias de imports (causa bugs si se confunden)

- `app/*` (sin `@`) → `frontend/packages/app/*` (lógica compartida: `lib/api-client.ts`, `provider/auth`, `features/<modulo>/api.ts` y `schemas.ts`).
- `@/*` → `frontend/apps/next/*` (UI web: `components/ui`, `features/<modulo>/`, `config/sidebar-nav.tsx`).
- `apps/next/app/` es el App Router (rutas). Nunca se importa con el alias `app/*`.

---

## Estado actual (2026-10-17)

Construido y probado:
- **Backend**: login (`POST /api/login`, usuario o correo, sin distinguir mayúsculas; cookie web o token Bearer de 14 días si se manda `device_name`), `POST /api/logout`, `GET /api/user`, CSRF de Sanctum activo en todo `/api`, middleware `active` que bloquea cuentas desactivadas, rate limiting de login (10/min por IP+cuenta, 30/min por IP), CORS con credenciales para `FRONTEND_URL`. 16 tests Pest en verde.
- **Frontend**: login, layout autenticado con sidebar filtrado por permisos y las pantallas de los 7 módulos más Inicio.
- **Feature 001-rbac** (`specs/001-rbac/`, RN-009..RN-020): roles (Administrador, Almacenista, Consulta precargados; un rol por usuario en `users.role_id`), 13 permisos `<modulo>.ver|gestionar` en `App\Enums\Permission`, matriz rol × módulo, gestión de usuarios y roles (sin borrado), contraseña temporal con cambio obligatorio (middleware `password.changed`), revocación de sesiones/tokens, sidebar y acciones filtradas por permisos (`useCan`, "Acceso denegado"). `usuarios.gestionar` es exclusivo del Administrador (RN-019); no hay `Gate::before`. 124 tests Pest y 96 Vitest en verde; quickstart verificado en navegador (Playwright).
- Repo en GitHub: `Lsickle/ActivosB31` (público), CI en verde. Identidad git local del repo: `LSickle`.
- **Feature 002-modulo-configuracion** (`specs/002-modulo-configuracion/`, RN-002..RN-004, RN-007, RN-011, RN-012): categorías, tipos de activo (modo serializado/por cantidad), tipos de ubicación y ubicaciones; CRUD sin borrado, búsqueda sin tildes, opciones disponibles en `GET /api/configuracion/options`, precarga (9 categorías, 34 tipos, 4 tipos de ubicación) solo si la tabla está vacía. 211 tests Pest y 145 Vitest en verde; quickstart verificado en navegador.
- **Features 003 a 008** (`specs/003..008`), todas con TDD, revisión de `especialista-seguridad` aplicada y verificación en navegador contra el backend real:
  - 003 seguridad: bitácora inmutable (`security_events`, trigger), bloqueo 5 fallos / 15 min (RN-034), desbloqueo por UI y `php artisan security:unlock`, IP con `trustProxies('REMOTE_ADDR')`.
  - 004 empleados (RN-021, RN-022, RN-035, RN-036, RN-040): cargos y compañías en Configuración, vínculo usuario-empleado (RN-015); Consulta sin `empleados.ver`.
  - 005 activos (RN-005..RN-008, RN-029..RN-031, RN-039): estados, historial inmutable, fotos re-codificadas sin EXIF (≤4 MB, disco `ASSETS_DISK`, R2 en producción), planes de mantenimiento por activo, vencimientos, alertas, `app_settings`.
  - 006 inventario (RN-027, RN-028, RN-037, RN-038): existencias, movimientos inmutables (`RecordStockMovement` es la única vía), conteos físicos, stock mínimo.
  - 007 entregas (RN-023..RN-026): consecutivo ENT-, firma PNG con huella SHA-256, devoluciones con estado, tenencias, alertas de préstamos vencidos.
  - 008 reportes (RN-032, RN-033): inventario, por empleado y por artículo en JSON/Excel/PDF; tablero de Inicio con alertas por permiso; exportaciones en la bitácora.
  - Suites: 797 tests Pest y 438 Vitest en verde. Librerías verificadas en `docs/infraestructura.md`.
- **Acta PDF y logo (2026-10-21, RN-041)**: `GET /api/deliveries/{id}/pdf` (permisos `view` + `viewSignature`, throttle `report-downloads`, 409 + `signature_integrity_failed` si la firma no es el PNG íntegro, evento `delivery_pdf_exported`, leyenda de copia en el pie, sin documento del empleado). Todo PDF pasa por `App\Pdf\PdfRenderer` (solo `data://` en tiempo de ejecución; la config global de dompdf sigue cerrada) con el layout `resources/views/pdf/layout.blade.php`: logo arriba a la derecha y marca de agua centrada en cada página. Logo: `backend/resources/branding/logo-b31.png` (≤ 300 KB y ≤ 2000 px; si falta o se pasa, PDF sin logo) y copia en `frontend/apps/next/public/logo-b31.png` y `app/icon.png` (favicon); componente `BrandLogo` en login, sidebar, barra superior e Inicio. Revisión de `especialista-seguridad` aplicada (M-1 lectura única de la firma, B-1 topes del logo). 882 tests Pest y 472 Vitest en verde.
  - El error "Jest worker encountered 2 child process exceptions" en `/entregas/[id]` era un `next dev` huérfano con la salida a una tubería cerrada (EPIPE); no era código. Si reaparece, detener el proceso del puerto 3001, borrar `apps/next/.next` y arrancar de nuevo.
- **Preguntas pendientes del usuario 26 a 36**: tabla al final de `docs/modulos.md` (33A ya implementada de forma provisional).

Siguiente paso sugerido:
1. Resolver las preguntas 26 a 36 y aplicar lo que cambie.
2. Pendientes de seguridad "antes de datos reales" (abajo).
3. Primer despliegue según `docs/despliegue.md`: Neon (`aws-us-east-1`), Render (Virginia), R2 (bucket de fotos y otro de respaldos), Vercel. Requiere las cuentas del usuario.

Entorno local: si los tests se corren como root dentro del contenedor, después ejecutar `docker compose exec -u root laravel.test chown -R sail:sail storage bootstrap/cache` (si no, la app no puede escribir firmas, fotos ni exportaciones). El `backend/.env` local necesita `LOCAL_DISK_SERVE=true` para servir fotos y firmas.

## Despliegue (preparado, pendiente de conectar)

Guía paso a paso: **`docs/despliegue.md`** (Neon, R2, Render, Cron Jobs, Vercel y las pruebas obligatorias del primer deploy). Resumen:

- **Render** (backend): Web Service Docker (Starter, Virginia), Root Directory `backend`, `Dockerfile.render` con **FrankenPHP 1.13.1 en modo clásico** (`backend/docker/Caddyfile`, usuario no root, health check `/up`). Al arrancar solo `php artisan optimize`; **migraciones y seeder van en el Pre-Deploy Command** con la conexión directa de Neon (`DB_MIGRATION_URL`). El seeder es idempotente y, en producción, falla si falta `ADMIN_INITIAL_PASSWORD` al tener que crear el admin (nunca genera ni imprime una contraseña); tras el primer deploy, borrarla de Render. **`SESSION_DOMAIN` vacío** (cookie host-only; `.vercel.app` está en la Public Suffix List); cookie `secure` por defecto con `APP_ENV=production`.
- **Neon**: rol dueño para migraciones (directa) y `activosb31_app` / `activosb31_backup` de mínimo privilegio con `ops/neon/roles.sql` (después del primer Pre-Deploy).
- **Cron Jobs de Render**: mantenimiento con la imagen de la app (`sanctum:prune-expired` diario y `deliveries:verify-signatures` los domingos, en un solo comando) y respaldo diario `pg_dump` a R2 con `ops/backup/`.
- **Vercel** (frontend): Root Directory `frontend/apps/next`, `BACKEND_URL`, `VERCEL_PROXY_SECRET` y `NEXT_PUBLIC_API_URL` vacío. `vercel.json` hace de proxy de `/api` y `/sanctum` con la cabecera `X-Proxy-Secret` (validada por `TrustVercelProxy`); debe probarse en un preview. `FRONTEND_URL` y `SANCTUM_STATEFUL_DOMAINS` del backend apuntan al dominio de Vercel. Sin preview deployments contra el backend real.

### Pendientes de seguridad (revisión de `especialista-seguridad`, 2026-10-17)

Ya corregido: login CSRF (se quitó la exención de `api/login`), enumeración por tiempo (hash ficticio), mensaje único para cuenta inactiva, contraseña del admin en logs de producción, cookie `secure`, `.dockerignore`, Composer fijado a 2.x.

Pendiente:
1. **En el primer deploy (implementado, falta verificar)**: `TrustVercelProxy` (middleware global con `prepend`, antes de `TrustProxies`) toma la IP real de `X-Real-IP` solo si `X-Proxy-Secret` coincide con `VERCEL_PROXY_SECRET` (o con `VERCEL_PROXY_SECRET_PREVIOUS` durante una rotación) y siempre elimina el secreto de la petición y de `$_SERVER`. `VERCEL_PROXY_REQUIRED=true` rechaza (403) lo que no llegue por el proxy, salvo `/up`: por defecto `false`; **recomendado `true` tras la prueba b** (B2, decisión del usuario). En Vercel no se activa el rewrite de `next.config.js` (solo el de `vercel.json`). Verificar IP real y spoofing con las pruebas b y c de `docs/despliegue.md`; el log temporal registra solo `ip()`, `X-Forwarded-For` y `X-Real-IP` (B8).
2. ~~Servidor de producción~~ (resuelto): FrankenPHP 1.13.1 en modo clásico en `Dockerfile.render`, `php artisan optimize` al arrancar, migraciones y seeder en el Pre-Deploy de Render (no al arrancar). Imagen construida y probada en local (extensiones, GD con WebP, límites de PHP, `/up` 200 sin base de datos, usuario no root).
3. ~~Bitácora de seguridad y bloqueo por intentos~~ (hecho en 003). Pendiente: recuperación de contraseña por correo cuando se defina el servicio de correo.
3b. **Antes de datos reales (revisión de seguridad de 007, Entregas)**:
   - Rol de la app en PostgreSQL sin ser dueño de las tablas y con `GRANT UPDATE (returned_quantity) ON delivery_items` por columna (además del trigger `delivery_items_guard_rows`): preparado en `ops/neon/roles.sql`, falta ejecutarlo en Neon (prueba f).
   - *Bucket lock* / retención (object lock) de R2 sobre el prefijo `signatures/` para que las firmas no se puedan sobrescribir ni borrar.
   - Buckets separados por entorno (local, staging, producción); nunca compartir el de producción.
   - Mencionar la firma manuscrita (dato personal) en el aviso de privacidad (Ley 1581).
   - ~~Límite de cuerpo HTTP~~ (hecho): `POST /api/deliveries` rechaza cuerpos de más de 1 MB con 413 (middleware `body.limit:1024`); Caddy corta todo cuerpo de más de 8 MB.
   - Ya hecho en UI: aviso "No escribas datos personales ni de salud." bajo las observaciones de entregas y devoluciones; revisar que se mantenga.
4. No conectar *preview deployments* de Vercel al backend real sin agregarlos explícitamente a CORS/Sanctum (nada de comodines).
5. **En Render (preparado)**: no se usa `schedule:run`; un Cron Job con la imagen de la app ejecuta `sanctum:prune-expired --hours=24` a diario y `deliveries:verify-signatures` los domingos (comando en `docs/despliegue.md`, sección 5.1). `SESSION_DRIVER=database` es requisito: la revocación de sesiones borra filas de `sessions`.
6. Riesgo aceptado por el usuario (M-4, Ley 1581): Almacenista y Consulta tienen `usuarios.ver` con correo y último ingreso; se puede retirar desde la matriz.
7. **Antes de datos reales (revisión 003/004)**: separar en Neon el rol dueño (migraciones, conexión directa) del rol de la app (pooler) con `GRANT SELECT, INSERT` sobre `security_events` y las tablas de historial (D-002 de 003); `ALTER ROLE <rol_app> SET timezone='UTC'` (ambos preparados en `ops/neon/roles.sql`; la migración condicional para tablas nuevas de solo inserción, pendiente de confirmación); tener 2 Administradores con usuario y correo no públicos; auditoría de cambios en empleados (E-1: quién crea/edita y valores anteriores) y copia de nombre y documento del empleado en cada entrega (007); obligaciones Ley 1581 (aviso de privacidad, política de tratamiento, RNBD si aplica, retención de datos de empleados inactivos y de la bitácora).
8. **Primer despliegue (D-009 de 003)**: verificar la IP real detrás de Render con y sin Vercel delante, ajustar proxies de confianza y encabezados (E-7). Runbook de desbloqueo del admin: `php artisan security:unlock` (research.md de 003), con credencial temporal de mínimo privilegio si se ejecuta en local.
9. **Pendientes de la revisión de 008**: R-6 (tenencias visibles en el reporte por empleado con `reportes.ver`, mientras `/api/employees/{id}/holdings` exige `empleados.ver`); R-11 (`/api/reports/employee-options` lista exempleados: política de tratamiento, Ley 1581); B-2 residual de 007 (con solo `entregas.gestionar`, `/api/inventory/stock` muestra `min_stock`/`below_min` y la búsqueda de activos filtra por marca/modelo); R-9 (rate limit para JSON de reportes y opciones si se escala); R-4 pendiente de la pregunta 36; `max_execution_time=60` verificado con FrankenPHP en la imagen local (falta confirmarlo en Render). Resuelto: `Delivery::signatureIntact()` se usa en `php artisan deliveries:verify-signatures` (semanal, domingos 03:00 America/Bogota; registra `signature_integrity_failed` en la bitácora y sale con FAILURE si hay firmas alteradas o faltantes; corre desde el Cron Job de mantenimiento del punto 5).
11. **Revisión de la preparación del despliegue (2026-10-18; detalle en `docs/despliegue.md`, sección 8)**. Hecho: A1-A8, B3/B9 (respaldo con `umask 077`, variables `PG*`, sin datos de tablas efímeras, verificación de `deliveries`/`security_events`, cifrado opcional con `age`). Pendiente:
   - **B1**: la credencial dueña (`DB_MIGRATION_URL`) queda en el entorno del Web Service en ejecución. Recomendado: migraciones desde GitHub Actions con *environment* protegido + Deploy Hook de Render (**pregunta 42 del usuario**). Nunca en el Environment Group ni en Cron Jobs.
   - **B3**: activar `BACKUP_AGE_RECIPIENT` (**pregunta 40 del usuario**, recomendado sí) y definir custodios de la clave privada.
   - **B4**: 2FA en Cloudflare (y GitHub, Render, Vercel, Neon) y bucket lock de `signatures/` antes de la primera firma real.
   - **B6**: probar `sslmode=verify-full&sslrootcert=system` en Neon (app y respaldo; la imagen de respaldo ya trae `ca-certificates` y usa `verify-full` por defecto).
   - **B7**: `trustHosts` cuando exista recuperación de contraseña por correo.
   - **B10**: quitar `ADMIN_INITIAL_PASSWORD` con redeploy, no con *Restart*.
   - Verificar en Neon que acepta roles creados sin contraseña y `\password` (si no, crearlos desde el panel).
10. Menores pendientes (revisión 001-rbac): carrera entre desactivar un rol y asignarlo a un usuario (falla cerrado: el usuario queda sin permisos); reactivar un usuario con rol inactivo; si existe el username `admin` sin rol Administrador y no hay ningún admin, el seeder falla con mensaje claro en vez de promoverlo.

Corregido en 001-rbac: índices `lower()` y normalización de username/email, `@` prohibido en username, cambio de contraseña, `sanctum:prune-expired` programado, escalada vía `usuarios.gestionar` (RN-019), reset de la propia contraseña (RN-018), seeder del admin por rol (no por username) y admin inicial con cambio obligatorio, sin `Gate::before`, throttle en cambio de contraseña, rotación de `remember_token`, duplicados en carrera → 422, cabeceras anti-clickjacking en Next.

---

## Reglas transversales

1. **Consulta `esquema-bd` antes de crear o modificar modelos o migraciones**, y actualízalo en el mismo cambio. No inventes columnas.
2. **Trazabilidad**: cuando existan reglas `RN-XXX` o casos de uso `CU-XXX` (se registran en `docs/modulos.md`), sus IDs van en comentarios, nombres de test y commits.
3. **No reinterpretes reglas de negocio en silencio.** Si algo es ambiguo o es una pregunta abierta de `docs/modulos.md`, pregunta al usuario.
4. **Verifica antes de fijar**: antes de agregar una dependencia o cambiar una versión, confirma con `stack-advisor` que sigue vigente; no te bases en memoria de entrenamiento.
5. **Seguridad**: antes de cerrar flujos de autenticación, RBAC, tokens o datos personales de empleados (Ley 1581 de 2012), pide una revisión a `especialista-seguridad`.
6. **TDD** (skill `tdd`) en todo cambio que no sea trivial. Flujo por tamaño de cambio en `.specify/memory/constitution.md` (Tier 0/1/2; Tier 2 usa spec-kit: `/speckit-specify` → `/speckit-plan` → `/speckit-tasks` → `/speckit-implement`).
7. **Monorepo**: backend y frontend viven en este mismo repo; un commit puede cerrar un cambio que toca ambas capas.
8. **Conventional Commits con scope por dominio**, no por capa: `feat(activos): registro de activos serializados`. `backend`/`frontend` como scope solo para cambios exclusivos de una capa sin módulo asociado; cambios de todo el repo sin scope (`chore: configurar CI`).
9. **Revisa decisiones punto por punto** con el usuario (una pregunta por decisión, agrupadas con AskUserQuestion), no en bloque.

## Herramientas de Claude Code en este repo

- **Subagentes** (`.claude/agents/`): `backend-laravel`, `frontend-web`, `qa-test-runner`, `especialista-seguridad`, `stack-advisor`. Los de dominio se invocan desde el hilo principal.
- **Skills** (`.claude/skills/`): `esquema-bd`, `tdd`, `laravel-best-practices`, `testing-best-practices` e `infer-conventions` (de Laravel Boost), y `speckit-*` (spec-kit).
- **Laravel Boost MCP**: configurado dentro del agente `backend-laravel` (vía `docker compose exec -T laravel.test php artisan boost:mcp`).
- `backend/CLAUDE.md` contiene las guías de Laravel Boost (se cargan al trabajar en `backend/`). Sus ejemplos usan `vendor/bin/sail`; en Windows sustitúyelo por `docker compose exec laravel.test`.
