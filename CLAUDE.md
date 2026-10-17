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
- **Frontend**: pantalla de login, layout autenticado con sidebar de los 7 módulos (placeholders) y cierre de sesión.
- **Feature 001-rbac** (`specs/001-rbac/`, RN-009..RN-020): roles (Administrador, Almacenista, Consulta precargados; un rol por usuario en `users.role_id`), 13 permisos `<modulo>.ver|gestionar` en `App\Enums\Permission`, matriz rol × módulo, gestión de usuarios y roles (sin borrado), contraseña temporal con cambio obligatorio (middleware `password.changed`), revocación de sesiones/tokens, sidebar y acciones filtradas por permisos (`useCan`, "Acceso denegado"). `usuarios.gestionar` es exclusivo del Administrador (RN-019); no hay `Gate::before`. 124 tests Pest y 96 Vitest en verde; quickstart verificado en navegador (Playwright).
- Repo en GitHub: `Lsickle/ActivosB31` (público), CI en verde. Identidad git local del repo: `LSickle`.
- Spec 002 (Configuración) escrita y con datos iniciales confirmados; falta plan, tareas e implementación.

Siguiente paso sugerido:
1. `/speckit-plan` → `/speckit-tasks` → implementar **002-modulo-configuracion** (`specs/002-modulo-configuracion/`), protegida con `configuracion.ver` / `configuracion.gestionar`.
2. Feature de seguridad antes de datos reales: bitácora de seguridad (A-1) + bloqueo por intentos fallidos.
3. Resolver preguntas abiertas de Empleados, Entregas e Inventario (`docs/modulos.md`); luego Activos (incluye mantenimientos RN-006), Inventario, Entregas y Reportes.
4. Primer despliegue en Render y Vercel.

Detalles conocidos: la matriz con 4+ roles desborda en horizontal la página en pantallas angostas (debería desplazarse dentro de la tabla); no hay `favicon.ico`.

---

## Despliegue (pendiente de conectar)

- **Render** (backend): Web Service tipo Docker, Root Directory `backend`, Dockerfile `Dockerfile.render`. Variables según `backend/.env.example` (`APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, `DB_*`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `ADMIN_INITIAL_PASSWORD`). **`SESSION_DOMAIN` se deja vacío** en modo proxy (cookie host-only; `.vercel.app` está en la Public Suffix List). La cookie es `secure` por defecto con `APP_ENV=production`. Al arrancar ejecuta `migrate --force --seed`; el seeder es idempotente y, en producción, falla si falta `ADMIN_INITIAL_PASSWORD` (nunca genera ni imprime una contraseña). Tras el primer arranque, borrar `ADMIN_INITIAL_PASSWORD` de Render.
- **Vercel** (frontend): Root Directory `frontend/apps/next`. Definir `BACKEND_URL=https://<servicio>.onrender.com` y dejar `NEXT_PUBLIC_API_URL` vacío: así `next.config.js` hace de proxy de `/api` y `/sanctum`, y la cookie de Sanctum queda en el mismo origen que la página. En ese caso `FRONTEND_URL` y `SANCTUM_STATEFUL_DOMAINS` del backend apuntan al dominio de Vercel.

### Pendientes de seguridad (revisión de `especialista-seguridad`, 2026-10-17)

Ya corregido: login CSRF (se quitó la exención de `api/login`), enumeración por tiempo (hash ficticio), mensaje único para cuenta inactiva, contraseña del admin en logs de producción, cookie `secure`, `.dockerignore`, Composer fijado a 2.x.

Pendiente:
1. **En el primer deploy**: confirmar qué IP ve Laravel detrás del proxy de Vercel (registrar temporalmente `$request->ip()` y `X-Forwarded-For`). Si es la IP de Vercel, el rate limit de 30/min por IP sería compartido por todos los usuarios: agregar un `middleware.ts` en Next que reenvíe la IP del cliente con un secreto compartido, validado en el backend.
2. **Antes de datos reales**: reemplazar `php artisan serve` en `Dockerfile.render` por un servidor de producción (FrankenPHP/Octane o nginx + php-fpm; verificar con `stack-advisor`), cachear config/rutas al arrancar y sacar el seeding del arranque (o usar un `AdminUserSeeder` dedicado).
3. **Antes de datos reales (feature aparte)**: bitácora de seguridad de solo inserción (quién crea usuarios, cambia roles, restablece contraseñas, edita la matriz; login/logout) y bloqueo por intentos fallidos. Recuperación de contraseña por correo cuando se defina el servicio de correo.
4. No conectar *preview deployments* de Vercel al backend real sin agregarlos explícitamente a CORS/Sanctum (nada de comodines).
5. **En Render**: el scheduler (`sanctum:prune-expired` diario en `routes/console.php`) necesita un cron job o worker. `SESSION_DRIVER=database` es requisito: la revocación de sesiones borra filas de `sessions`.
6. Riesgo aceptado por el usuario (M-4, Ley 1581): Almacenista y Consulta tienen `usuarios.ver` con correo y último ingreso; se puede retirar desde la matriz.
7. Menores pendientes (revisión 001-rbac): carrera entre desactivar un rol y asignarlo a un usuario (falla cerrado: el usuario queda sin permisos); reactivar un usuario con rol inactivo; si existe el username `admin` sin rol Administrador y no hay ningún admin, el seeder falla con mensaje claro en vez de promoverlo.

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
