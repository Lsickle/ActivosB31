# Despliegue de producción

Guía paso a paso para el primer despliegue de ActivosB31. Las decisiones y versiones están verificadas con `stack-advisor` (2026-10-17); ver `docs/infraestructura.md`. **Nada de esto está conectado todavía**: cada servicio se crea con las cuentas nuevas del proyecto (ver "Cuentas de servicios externos" en `CLAUDE.md`), nunca con las de EcoLink.

Arquitectura:

```
Navegador ──HTTPS──> Vercel (Next.js, frontend/apps/next)
                       │  /api/* y /sanctum/* → rewrite de vercel.json
                       │  + X-Proxy-Secret (VERCEL_PROXY_SECRET)
                       ▼
                     Render Web Service (Docker, backend/Dockerfile.render)
                       FrankenPHP 1.13.1 (modo clásico) + PHP 8.4
                       │                     │
                       ▼                     ▼
              Neon PostgreSQL 17      Cloudflare R2 (bucket de fotos y firmas)
              (aws-us-east-1)
                       ▲
                       │ pg_dump diario
              Render Cron Job (ops/backup) ──> R2 (bucket de respaldos, otro token)
```

Orden recomendado: Neon → R2 → Render (Web Service) → `ops/neon/roles.sql` → Render (Cron Jobs) → Vercel → pruebas obligatorias.

Ningún valor real (contraseñas, claves, URLs con credenciales) va en el repo, en tickets ni en el chat: solo en el panel de cada servicio y en un gestor de contraseñas.

---

## 1. Neon (PostgreSQL)

1. Crear un proyecto: región **AWS US East 1 (N. Virginia)** (`aws-us-east-1`, la misma zona que Render Virginia), **PostgreSQL 17**. Base de datos `neondb` (o `activosb31`) con el rol dueño que crea Neon (p. ej. `neondb_owner`).
2. Dos cadenas de conexión (panel *Connect*):
   - **Directa** (host sin `-pooler`): para migraciones, el seeder, `roles.sql` y los respaldos (`pg_dump` necesita una sesión real).
   - **Pooler** (host con `-pooler`, PgBouncer en modo transacción): para la aplicación. En modo transacción los `SET` de sesión que hace Laravel al conectar no se garantizan entre transacciones; por eso `timezone`, `search_path` y `statement_timeout` se fijan **por rol** en `roles.sql` (`ALTER ROLE ... SET`).
   - Ambas con `sslmode=require`.
3. Plan Free: 1 GB por proyecto, la base se suspende tras 5 min sin uso (la primera petición tarda un poco más: ver prueba d) y PITR de 6 h. El PITR **no** es un respaldo: el respaldo es el Cron Job de la sección 5.
4. Roles de mínimo privilegio: se crean con `ops/neon/roles.sql` **después** del primer deploy (sección 4), cuando las migraciones ya crearon las tablas.

## 2. Cloudflare R2

Dos buckets en la misma cuenta, **sin acceso público** (sin dominio `r2.dev` ni dominio propio; las fotos y firmas se sirven solo con `temporaryUrl()`):

| Bucket | Uso | Token (API token de R2) |
|---|---|---|
| `activosb31-archivos` (nombre sugerido) | Fotos de activos (`assets/`) y firmas de entregas (`signatures/`) | *Object Read & Write* **solo** sobre este bucket → `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` del Web Service y del Cron Job de la app |
| `activosb31-respaldos` (nombre sugerido) | `pg_dump` diarios y mensuales | *Object Read & Write* **solo** sobre este bucket → `BACKUP_R2_*` del Cron Job de respaldo |

La app no puede leer ni borrar respaldos, y el respaldo no puede tocar fotos ni firmas. Nunca tokens de cuenta ni de administración. Un bucket por entorno: el de producción no se comparte con local ni con staging.

Reglas del bucket de **respaldos**:
- *Object lifecycle*: borrar `daily/` a los **35 días** y `monthly/` a los **365 días**.
- *Bucket lock* (retención): `daily/` **30 días** y `monthly/` **365 días**. Así un token filtrado no puede borrar ni sobrescribir los respaldos recientes. (El script usa nombres con fecha y hora, por lo que nunca intenta sobrescribir.)

Regla del bucket de **archivos**:
- *Bucket lock* sobre el prefijo `signatures/` (pendiente 3b de `CLAUDE.md`): las firmas de entrega (RN-024) no se pueden sobrescribir ni borrar. Periodo: **5 años** (pregunta 43, 2026-10-21; política de retención, Ley 1581).

Endpoint S3 de la cuenta: `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` (es el mismo para los dos buckets).

**Antes de crear los buckets (B4 de la revisión del despliegue)**: activar la verificación en dos pasos (2FA) de la cuenta de Cloudflare, y también de GitHub, Render, Vercel y Neon. Quien entra al panel de Cloudflare puede quitar el bucket lock y borrar firmas y respaldos; el bucket lock del prefijo `signatures/` del bucket de archivos (RN-024) y el de `daily/`/`monthly/` del de respaldos se configuran **antes** de la primera subida real.

## 3. Render: Web Service (backend)

Crear un **Web Service** desde el repositorio:

| Campo | Valor |
|---|---|
| Runtime | Docker |
| Región | **Virginia (US East)** |
| Plan | **Starter** (512 MB; el Caddyfile y `GOMEMLIMIT` están dimensionados para eso) |
| Root Directory | `backend` |
| Dockerfile Path | `Dockerfile.render` |
| Health Check Path | `/up` (no toca la base de datos: verificado con la imagen local) |
| Pre-Deploy Command | `sh -c 'DB_URL="$DB_MIGRATION_URL" php artisan migrate --force && DB_URL="$DB_MIGRATION_URL" php artisan db:seed --force'` |
| Auto-Deploy | Solo desde `main` |

El contenedor arranca con `php artisan optimize && exec frankenphp run --config /etc/frankenphp/Caddyfile`: cachea config, rutas, eventos y vistas con las variables de Render y sirve en `$PORT` (8080). **No** migra ni siembra al arrancar (eso es el Pre-Deploy, con la conexión directa y el rol dueño). La imagen no cachea configuración en el build: por eso el `DB_URL="$DB_MIGRATION_URL"` del Pre-Deploy sí tiene efecto.

### Variables de entorno

Recomendado: un **Environment Group** `activosb31-app` compartido por el Web Service y el Cron Job de mantenimiento (sección 5.1), para que ambos tengan la misma configuración. **`DB_MIGRATION_URL` nunca va en el Environment Group ni en ningún Cron Job** (ver B1 abajo).

> **B1 (riesgo aceptado por el usuario el 2026-10-21, pregunta 42 = B: migraciones en el Pre-Deploy de Render)**: Render pasa las mismas variables al Pre-Deploy y al contenedor en ejecución, así que con el Pre-Deploy de abajo la credencial del rol **dueño** (`DB_MIGRATION_URL`: DDL, puede quitar triggers y REVOKE de las tablas de solo inserción) queda en el entorno del Web Service mientras atiende peticiones. Una RCE o una fuga de variables (p. ej. un `APP_DEBUG=true` accidental) la expondría. Opción **recomendada**: sacar las migraciones de Render y correrlas desde GitHub Actions en un *environment* protegido (`production`, con revisores obligatorios y `DB_MIGRATION_URL` como secreto del environment), y al terminar disparar el **Deploy Hook** de Render (secreto `RENDER_DEPLOY_HOOK_URL`, con Auto-Deploy desactivado). Así el Web Service solo tiene `DB_URL` (`activosb31_app`). Mientras el usuario no decida, se usa el Pre-Deploy de esta sección, aceptando ese riesgo. El workflow **no** está creado.

| Variable | Valor / descripción |
|---|---|
| `APP_NAME` | `ActivosB31` |
| `APP_ENV` | `production` (activa la cookie `secure`, el chequeo M-4 de `ASSETS_DISK` y la exigencia de `ADMIN_INITIAL_PASSWORD`) |
| `APP_DEBUG` | `false` |
| `APP_KEY` | `base64:...` generado una sola vez (`php artisan key:generate --show` en local). Cambiarla invalida sesiones y datos cifrados. |
| `APP_URL` | URL pública del backend (`https://<servicio>.onrender.com`) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | `es` / `en` |
| `LOG_LEVEL` | `info` (la imagen ya fija `LOG_CHANNEL=stderr`: los logs van al panel de Render) |
| `BCRYPT_ROUNDS` | `12` |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | Conexión **pooler** de Neon con el rol `activosb31_app`: `postgresql://activosb31_app:<contraseña>@<host>-pooler.../neondb?sslmode=require` |
| `DB_SSLMODE` | `require` (B6: probar `verify-full`, ver abajo) |
| `DB_MIGRATION_URL` | Conexión **directa** de Neon con el rol dueño (`neondb_owner`). Solo la usa el Pre-Deploy. Solo en el Web Service, nunca en el Environment Group (B1). |
| `SESSION_DRIVER` | `database` (requisito: la revocación de sesiones borra filas de `sessions`, FR-011) |
| `SESSION_LIFETIME` | `120` |
| `SESSION_DOMAIN` | **vacío** (cookie host-only; `.vercel.app` está en la Public Suffix List) |
| `CACHE_STORE` | `database` |
| `QUEUE_CONNECTION` | `database` (hoy no hay jobs en cola; no hace falta worker) |
| `MAIL_MAILER` | `log` hasta que se defina el servicio de correo |
| `FRONTEND_URL` | URL de Vercel (`https://<proyecto>.vercel.app` o el dominio propio). CORS con credenciales. |
| `SANCTUM_STATEFUL_DOMAINS` | Host de Vercel sin esquema (`<proyecto>.vercel.app`). Nada de comodines ni de previews. |
| `ASSETS_DISK` | `r2` (la app no arranca en producción con otro valor, M-4) |
| `LOCAL_DISK_SERVE` | sin definir o `false` (M-3) |
| `R2_ACCESS_KEY_ID` / `R2_SECRET_ACCESS_KEY` | Token *Object Read & Write* del bucket de archivos |
| `R2_BUCKET` | Bucket de archivos |
| `R2_ENDPOINT` | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` |
| `VERCEL_PROXY_SECRET` | Secreto compartido con Vercel (`openssl rand -hex 32`). El mismo valor en Vercel. |
| `VERCEL_PROXY_SECRET_PREVIOUS` | Vacío. Solo durante una rotación del secreto (sección 6). |
| `VERCEL_PROXY_REQUIRED` | `false` en el primer deploy. `true` rechaza (403) todo lo que no llegue por el proxy de Vercel, salvo `/up`. **Recomendado ponerlo en `true` en cuanto pase la prueba b** (B2); decisión del usuario. |
| `ADMIN_INITIAL_PASSWORD` | Solo para el **primer** deploy (el seeder crea el admin con cambio obligatorio de contraseña). **Borrarla de Render después** (y redeploy). |

`PORT`, `LOG_CHANNEL` y `GOMEMLIMIT=450MiB` ya vienen en la imagen.

**B6 (por probar)**: `sslmode=require` cifra pero no verifica el certificado de Neon. En el primer deploy, probar en `DB_URL` y `DB_MIGRATION_URL` `?sslmode=verify-full&sslrootcert=system` (libpq ≥ 16 usa el almacén de CA del sistema; la imagen es Debian trixie con `ca-certificates`) y `DB_SSLMODE=verify-full`. Si conecta, dejarlo así; si falla, volver a `require` y anotarlo. libpq solo admite `sslrootcert=system` con `verify-full`.

### Primer deploy

1. Crear el servicio con las variables (incluida `ADMIN_INITIAL_PASSWORD`). El Pre-Deploy migra y crea el admin con la conexión directa. El arranque y `/up` funcionan aunque `activosb31_app` todavía no exista (no tocan la base), pero la app no puede atender peticiones hasta el paso 3.
2. Ejecutar `ops/neon/roles.sql` y fijar las contraseñas con `\password` (sección 4).
3. Poner `DB_URL` con `activosb31_app` y guardar con **Save and deploy** (un deploy nuevo, no *Restart*: así se garantiza que el contenedor arranca con las variables nuevas y que `php artisan optimize` las vuelve a cachear).
4. Iniciar sesión con `admin` y cambiar la contraseña.
5. Borrar `ADMIN_INITIAL_PASSWORD` de Render y hacer un **redeploy** (*Save and deploy* o *Manual Deploy → Deploy latest commit*), **no** un *Restart* (B10): el objetivo es que la variable desaparezca del entorno del contenedor y de la configuración cacheada; un reinicio no da esa garantía de forma explícita. Comprobar en el *Environment* del servicio que ya no aparece.
6. Correr las pruebas obligatorias (sección 7).

### Shell de Render y runbook de desbloqueo

El *Shell* del servicio abre una terminal en el contenedor (usuario `app`, directorio `/app`) con las mismas variables. Usarlo solo para tareas puntuales; cada uso, anotado.

Desbloqueo del administrador (RN-034, 003) si quedó bloqueado por intentos fallidos o no queda otro admin para desbloquearlo desde la UI:

```bash
php artisan security:unlock <usuario-o-correo>
```

Queda registrado en la bitácora (`account_unlocked`). No reinicia la contraseña: si se olvidó, otro Administrador la restablece desde Usuarios.

## 4. Roles de la base: `ops/neon/roles.sql`

Después del primer Pre-Deploy (las tablas ya existen), desde una máquina con `psql` 17 y la conexión **directa** con el rol dueño (`psql` pide su contraseña):

```bash
# 1. Roles, parámetros y privilegios (sin contraseñas).
psql "postgresql://neondb_owner@<host-directo>/neondb?sslmode=require" \
  -v ON_ERROR_STOP=1 -f ops/neon/roles.sql

# 2. Contraseñas, en una sesión interactiva (A2/A3 de la revisión del despliegue).
openssl rand -hex 32        # una por rol; guardarla en el gestor de contraseñas
psql "postgresql://neondb_owner@<host-directo>/neondb?sslmode=require"
neondb=> \password activosb31_app
neondb=> \password activosb31_backup
```

`roles.sql` crea los roles **sin contraseña** (no pueden iniciar sesión hasta el paso 2) y nunca las toca. `\password` pide la contraseña sin mostrarla y envía al servidor solo el hash SCRAM-SHA-256: la contraseña en claro no queda en argv, en variables de `psql`, en `~/.psql_history` ni en los logs del servidor. Para rotar una contraseña: repetir `\password` y actualizar la variable en Render (con redeploy).

Crea `activosb31_app` (DML sin DDL; sin `UPDATE`/`DELETE`/`TRUNCATE` en las tablas de solo inserción; en `delivery_items` solo `UPDATE (returned_quantity)`; en `migrations` solo `SELECT`; sin `GRANT EXECUTE`, porque las únicas funciones son de trigger) y `activosb31_backup` (solo lectura), con `timezone=UTC`, `search_path=public` y `statement_timeout` por rol. Es idempotente: volver a ejecutarlo no recrea los roles ni cambia sus contraseñas, solo reaplica parámetros, `GRANT` y `REVOKE`. Si una migración futura crea otra tabla de solo inserción, agregarla al archivo y volver a ejecutarlo tras el deploy (la migración condicional que lo automatice está **pendiente de confirmación del usuario**). Se validó (2026-10-18) contra el PostgreSQL 17 local con el esquema real: dos ejecuciones seguidas, contraseña SCRAM conservada, *permission denied* en `UPDATE delivery_items.quantity`, `DELETE security_events` y `INSERT/DELETE migrations`, `UPDATE (returned_quantity)` y `nextval` permitidos, y el rol de respaldo sin escritura (prueba f para la verificación en Neon).

**Por verificar en Neon**: que acepta `CREATE ROLE ... LOGIN` sin contraseña desde SQL y luego el hash SCRAM de `\password` (Neon impone reglas propias a las contraseñas de roles creados por SQL). Si lo rechaza, crear los dos roles desde el panel de Neon (*Roles*), que genera la contraseña, y ejecutar `roles.sql` igual (no los recrea).

Luego, en Render: `DB_URL` con `activosb31_app` (pooler) y las variables `PG*` del Cron Job de respaldo con `activosb31_backup` (directa, sección 5.2).

## 5. Render: Cron Jobs

Render ejecuta cada Cron Job como un contenedor que arranca, corre el comando y termina (horarios en **UTC**). No se usa `schedule:run`: los dos comandos programados en `routes/console.php` se ejecutan directamente.

### 5.1 Mantenimiento de la app (`activosb31-mantenimiento`)

Un solo Cron Job con la imagen de la app (lo más simple: sin comando nuevo ni otro servicio):

| Campo | Valor |
|---|---|
| Runtime | Docker, Root Directory `backend`, Dockerfile Path `Dockerfile.render` |
| Región / plan | Virginia / el más pequeño |
| Schedule | `0 8 * * *` (08:00 UTC = 03:00 America/Bogota) |
| Command | `sh -c 'php artisan sanctum:prune-expired --hours=24 && if [ "$(date -u +%u)" = 7 ]; then php artisan deliveries:verify-signatures; fi'` |
| Variables | El Environment Group `activosb31-app` (necesita `DB_URL`, `APP_KEY`, `ASSETS_DISK=r2` y `R2_*`: la verificación lee las firmas de R2). **Nunca** `DB_MIGRATION_URL` (B1). |

- Todos los días: `sanctum:prune-expired --hours=24` borra tokens Bearer vencidos (FR-016, D-009).
- Domingos (día 7 en UTC; a las 08:00 UTC ya es domingo en Bogotá): `deliveries:verify-signatures` (M-3 de 007/008, RN-024). Si encuentra firmas alteradas o faltantes las registra en la bitácora y sale con error: el Cron Job aparece como fallido en Render (configurar la notificación por correo de fallos del servicio).

### 5.2 Respaldo de la base (`activosb31-respaldo`)

| Campo | Valor |
|---|---|
| Runtime | Docker, Root Directory `ops/backup`, Dockerfile Path `Dockerfile` |
| Imagen | `postgres:17.11-alpine3.24` + rclone 1.75.2 + `age` (1.3.2 al construir el 2026-10-18, paquete de Alpine) + `ca-certificates` (`ops/backup/Dockerfile`) |
| Schedule | `30 7 * * *` (07:30 UTC = 02:30 America/Bogota) |
| Command | vacío (usa `/usr/local/bin/backup.sh`) |

Variables (no van en el Environment Group de la app). La conexión va en las variables estándar de libpq y **no** como URL: `pg_dump --dbname=<url>` dejaría la contraseña en argv, visible en `ps` (B9). El script se niega a correr si encuentra la antigua `BACKUP_DATABASE_URL`.

| Variable | Descripción |
|---|---|
| `PGHOST` | Host **directo** de Neon (sin `-pooler`) |
| `PGUSER` | `activosb31_backup` |
| `PGPASSWORD` | Contraseña de `activosb31_backup` (la fijada con `\password`) |
| `PGDATABASE` | `neondb` (o el nombre de la base) |
| `PGSSLMODE` | Opcional. Por defecto `verify-full` con `PGSSLROOTCERT=system` (B6: verifica certificado y nombre de host con las CA de `ca-certificates`). **Por probar en el primer deploy**; si Neon falla con `verify-full`, `require` mientras se resuelve. |
| `BACKUP_BUCKET` | Bucket de respaldos |
| `BACKUP_R2_ACCESS_KEY_ID` / `BACKUP_R2_SECRET_ACCESS_KEY` | Token *Object Read & Write* del bucket de respaldos |
| `BACKUP_R2_ENDPOINT` | `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` |
| `BACKUP_AGE_RECIPIENT` | Opcional (B3, **pregunta 40: el usuario eligió no cifrar (2026-10-21)**; dejarla sin definir). Una o varias claves **públicas** de age (`age1...`) separadas por espacios. Sin ella el respaldo se sube sin cifrar y el log lo advierte. |

El script (`set -eu`, `umask 077`, sin imprimir secretos):

1. `pg_dump -Fc -Z 6` del esquema completo **sin las filas** de `sessions`, `cache`, `cache_locks`, `jobs` y `failed_jobs` (`--exclude-table-data`): no hacen falta para restaurar y contienen IP, user agent y payloads (minimización, Ley 1581). Se restauran vacías.
2. Verifica el archivo con `pg_restore --list`: debe tener datos de tablas y, en particular, de `deliveries` y `security_events`.
3. Si hay `BACKUP_AGE_RECIPIENT`, cifra con `age` y borra el dump en claro antes de subir (sube `*.dump.age`).
4. Lo sube a `daily/activosb31-<fecha>T<hora>Z.dump[.age]` y, el día 01 (UTC), también a `monthly/`.

Falla (y Render lo marca) si falta una variable, si el dump, la verificación o el cifrado fallan o si R2 no responde (tiempos acotados, sin quedarse colgado). Probado en local (2026-10-18) contra la base de Sail y un servidor S3 local (`rclone serve s3`): con y sin cifrado, descifrado con la clave privada y restauración en una base vacía (mismos conteos de `deliveries` y `security_events`; `sessions` vacía). La subida real a R2 y `verify-full` contra Neon se prueban en el primer deploy.

**Claves de age**: generarlas **fuera** de Render, en una máquina de confianza (`age-keygen -o activosb31-respaldos.key`). En Render solo va la clave pública (`age1...`). La clave privada se guarda en el gestor de contraseñas (y una copia offline, p. ej. impresa en un sobre sellado): sin ella los respaldos cifrados no se pueden restaurar. Recomendado: dos destinatarios (dos claves de dos custodios) para no depender de una sola persona.

Restauración (simulacro de la prueba g, nunca sobre la base de producción): crear una **rama** de Neon, descargar el archivo con rclone o desde el panel de R2 y

```bash
# Solo si está cifrado (.dump.age):
age -d -i activosb31-respaldos.key -o activosb31-<fecha>.dump activosb31-<fecha>.dump.age

# Conexión por variables (no en argv), con el rol dueño de la rama:
export PGHOST=<host-directo-de-la-rama> PGUSER=neondb_owner PGDATABASE=neondb PGSSLMODE=verify-full PGSSLROOTCERT=system
read -rs PGPASSWORD && export PGPASSWORD
pg_restore --clean --if-exists --no-owner activosb31-<fecha>.dump
```

Borrar el `.dump` descifrado al terminar.

## 6. Vercel (frontend)

| Campo | Valor |
|---|---|
| Root Directory | `frontend/apps/next` |
| Framework | Next.js (Next 16) |
| `BACKEND_URL` | `https://<servicio>.onrender.com` (sin `/` final) |
| `VERCEL_PROXY_SECRET` | El mismo valor que en Render. Marcado como *Sensitive*. |
| `NEXT_PUBLIC_API_URL` | **vacío** (mismo origen: la cookie de Sanctum queda first-party) |

- `frontend/apps/next/vercel.json` reenvía `/(api|sanctum)/(.*)` a `${BACKEND_URL}/$1/$2`, agrega `x-proxy-secret` con `$VERCEL_PROXY_SECRET` (transform `request.headers`) y desactiva el caché del rewrite (`x-vercel-enable-rewrite-caching: 0`). Del lado de Laravel, `TrustVercelProxy` solo confía en `X-Real-IP` si el secreto coincide, y elimina `X-Proxy-Secret` de la petición.
- La ruta declara `"env": ["BACKEND_URL"]` (lo usa `dest`) y la transformación su propio `"env": ["VERCEL_PROXY_SECRET"]` (A1 de la revisión del despliegue).
- **Debe probarse en un preview antes de producción** (prueba c): la combinación de `routes` de `vercel.json` con Next 16 y la interpolación de variables de entorno en `dest` y en `transforms` no se pudo verificar sin cuenta. En Vercel (`process.env.VERCEL` definido en el build) el `rewrites` de `next.config.js` **no se activa** (B2): el único proxy es el de `vercel.json`. Si `vercel.json` no se aplica, `/api` y `/sanctum` responden 404 y el login no funciona (falla visible en la prueba c), en vez de funcionar en silencio sin el secreto y con la IP de Vercel. El rewrite de `next.config.js` queda para `next start` fuera de Vercel.
- En desarrollo local nada cambia: `yarn dev` no lee `vercel.json` y, sin `BACKEND_URL`, `next.config.js` no hace rewrites.

### Rotación de `VERCEL_PROXY_SECRET` (B2)

Sin cortar el servicio:

1. Generar el nuevo valor (`openssl rand -hex 32`).
2. En Render: `VERCEL_PROXY_SECRET_PREVIOUS` = valor actual, `VERCEL_PROXY_SECRET` = valor nuevo; redeploy. `TrustVercelProxy` acepta los dos.
3. En Vercel: `VERCEL_PROXY_SECRET` = valor nuevo; redeploy de producción.
4. Comprobar (bitácora o log temporal de la prueba b) que las peticiones siguen viendo la IP real; entonces vaciar `VERCEL_PROXY_SECRET_PREVIOUS` en Render y redeploy.

Rotarlo también si se sospecha una fuga (logs, capturas, una persona que deja el proyecto).
- **Sin preview deployments contra el backend real**: en *Settings → Environment Variables* definir `BACKEND_URL` y `VERCEL_PROXY_SECRET` solo para *Production*. Los previews no están en `FRONTEND_URL` ni en `SANCTUM_STATEFUL_DOMAINS` (nada de comodines). Para probar un preview, usar un backend de staging con sus propios Neon y R2.

## 7. Pruebas obligatorias del primer deploy

Ninguna se puede hacer sin las cuentas; anotar el resultado de cada una antes de cargar datos reales.

- **a. Foto de 4 MB por el rewrite de Vercel**: subir una foto de activo de ~4 MB (JPEG de celular) desde la app en Vercel. El límite de cuerpo de Vercel (~4,5 MB) debe dejarla pasar; Caddy corta en 8 MB y PHP en `post_max_size=8M`. Si falla con 413 de Vercel, bajar `max:4096` de `AssetPhotoRules`.
- **b. IP real y spoofing**: con un log temporal que registre **solo** `$request->ip()` y las cabeceras `X-Forwarded-For` y `X-Real-IP` (B8: nunca todas las cabeceras ni `$request->server`, que incluyen la cookie de sesión, el token Bearer y, antes de `TrustVercelProxy`, `X-Proxy-Secret`; quitarlo después con un redeploy), comprobar que (1) por Vercel Laravel ve la IP real del cliente; (2) un `X-Real-IP` o `X-Forwarded-For` inventado por el cliente no cambia la IP (Vercel sobrescribe `X-Real-IP`; sin secreto, `TrustVercelProxy` lo ignora); (3) una petición directa a `onrender.com` sin secreto ve su propia IP. Revisar la IP de los eventos en la bitácora. Si todo cuadra, poner `VERCEL_PROXY_REQUIRED=true` (recomendado, B2; decisión del usuario) y redeploy: desde ahí una petición directa a `onrender.com` recibe 403 (salvo `/up`).
- **c. `vercel.json` con Next 16**: en un preview (contra un backend de staging) confirmar que se aplica el rewrite de `vercel.json` (en Vercel el de `next.config.js` está desactivado: si `/api` da 404, `vercel.json` no se aplicó), que llega `X-Proxy-Secret` con el valor de la variable (no el texto literal `$VERCEL_PROXY_SECRET`) y que las respuestas de `/api` no se cachean.
- **d. Suspensión de Neon (`sleep(65)`)**: dejar la base inactiva más de 5 min (o simularlo con una petición que espere 65 s) y verificar que la primera petición siguiente responde (reconexión del pooler, sin errores 500 y dentro de `max_wait_time` / `max_execution_time`).
- **e. Memoria con dos subidas simultáneas**: dos fotos grandes (24 Mpx) a la vez. Con `num_threads 2` y `memory_limit=256M` el servicio no debe reiniciarse por memoria (Render → *Metrics*); una tercera petición espera hasta 15 s y luego recibe 503.
- **f. GRANT por columna**: con la app usando `activosb31_app`, registrar una devolución (actualiza `delivery_items.returned_quantity`) y comprobar que funciona; desde `psql` con ese rol, `UPDATE delivery_items SET quantity = quantity` y `DELETE FROM security_events` deben fallar con *permission denied*. Correr las consultas de verificación del final de `roles.sql`.
- **g. Restauración de prueba**: tras el primer respaldo, restaurarlo en una rama de Neon (sección 5.2) y comprobar que la app de staging (o un `psql`) ve los datos.

Además: confirmar en los logs que `max_execution_time=60` aplica con FrankenPHP (pendiente 9 de `CLAUDE.md`; en la imagen local lo reporta el `ini_get` de una petición web) y que la primera escritura real a R2 (`put` de una foto) funciona con `request_checksum_calculation=when_required`.

## 8. Revisión de seguridad de la preparación del despliegue (2026-10-18)

Aplicado en el repo:

- **A1** `vercel.json`: `env` propio de la transformación (`VERCEL_PROXY_SECRET`); la ruta solo declara `BACKEND_URL`.
- **A2/A3** `roles.sql` crea los roles sin contraseña; se fijan con `\password` (SCRAM, sin texto plano). Reejecutarlo solo aplica parámetros, `GRANT` y `REVOKE`.
- **A4** `TrustVercelProxy` va al inicio del stack global (`prepend`) y borra el secreto de la petición y de `$_SERVER`. Acepta `VERCEL_PROXY_SECRET_PREVIOUS` durante una rotación (sección 6).
- **A5** `POST /api/deliveries` responde 415 si el cuerpo no es JSON (middleware `json.body`).
- **A6** Caddy agrega `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY` y quita `Server`.
- **A7** `.dockerignore` excluye `storage/app/*`, `tests`, `phpunit.xml`, `.github` y `*.sqlite`; los directorios de `storage` se crean vacíos en el Dockerfile.
- **A8** `activosb31_app` sin `INSERT/UPDATE/DELETE` en `migrations` y sin `GRANT EXECUTE`.
- **B2** (parcial) en Vercel no se activa el rewrite de `next.config.js`.
- **B3/B9** respaldo con `umask 077`, conexión por variables `PG*`, verificación de `deliveries` y `security_events`, sin datos de tablas efímeras y cifrado opcional con `age`.

Pendiente (no se puede hacer sin las cuentas o requiere decisión del usuario):

- **B1**: credencial dueña (`DB_MIGRATION_URL`) en el entorno del Web Service en ejecución. Recomendado: migraciones desde GitHub Actions con *environment* protegido + Deploy Hook de Render (**pregunta 42**: el usuario eligió B el 2026-10-21, Pre-Deploy de Render, riesgo aceptado). Siempre, nunca en el Environment Group ni en Cron Jobs (sección 3).
- **B2**: `VERCEL_PROXY_REQUIRED=true` tras la prueba b.
- **B3 (riesgo aceptado, pregunta 40 = no cifrar, 2026-10-21)**: los respaldos quedan sin cifrar en el bucket privado de respaldos; su protección es el token exclusivo, el bucket lock y la 2FA de Cloudflare.
- **B4**: 2FA en Cloudflare (y en GitHub, Render, Vercel y Neon) y bucket lock de `signatures/` antes de la primera firma real (sección 2).
- **B6**: probar `sslmode=verify-full&sslrootcert=system` en `DB_URL`, `DB_MIGRATION_URL` y el respaldo (secciones 3 y 5.2).
- **B7**: cuando exista recuperación de contraseña por correo, configurar `trustHosts` (`$middleware->trustHosts(...)` en `bootstrap/app.php`) con el host del backend, para que los enlaces del correo no se puedan generar con un `Host` inyectado.
- **B8**: el log temporal de la prueba b registra solo `ip()`, `X-Forwarded-For` y `X-Real-IP`.
- **B10**: quitar `ADMIN_INITIAL_PASSWORD` con redeploy, no con *Restart* (sección 3, paso 5).
- Verificar en Neon la creación de roles sin contraseña + `\password` (sección 4).
