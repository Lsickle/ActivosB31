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
              Neon PostgreSQL 17      Backblaze B2 (US East, API S3)
              (aws-us-east-1)           activosb31-fotos   (llave de fotos)
                       ▲                activosb31-firmas  (otra llave, Object Lock)
                       │ pg_dump diario
              Render Cron Job (ops/backup) ──> B2 activosb31-respaldos
                                               (otra llave, Object Lock, rclone b2)
```

Orden recomendado: Neon → Backblaze B2 → Render (Web Service) → `ops/neon/roles.sql` → Render (Cron Jobs) → Vercel → pruebas obligatorias (incluida la prueba de humo de B2, sección 2.6).

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

## 2. Backblaze B2

Decisiones del usuario (2026-10-21, preguntas 44 a 47 de `docs/modulos.md`): almacenamiento en **Backblaze B2** en vez de Cloudflare R2 (no pide tarjeta), **tres buckets privados**, firmas con Object Lock (governance durante las pruebas, **compliance de 1825 días** antes de la primera firma real) y respaldos en un solo bucket con retención por defecto de 30 días.

### 2.1 Cuenta

1. Registrar la cuenta con el correo nuevo del proyecto (no el de EcoLink) eligiendo la región **US East**. **La región se elige al registrarse y no se puede cambiar** (otra región = otra cuenta). El endpoint S3 es del tipo `https://s3.us-east-005.backblazeb2.com`; la región exacta (p. ej. `us-east-005`) aparece en los detalles del bucket como *Endpoint*. Los datos (firmas, fotos y respaldos con datos de empleados) quedan **en EE. UU.**: ver el pendiente de Ley 1581 (sección 8).
2. Activar la verificación en dos pasos (2FA) **antes** de crear los buckets (B4). Quien entra al panel puede borrar fotos, descargar los respaldos y, mientras estén en governance, quitar la retención de las firmas.
3. *Caps & Alerts* (M2): fijar un **tope diario de almacenamiento** razonable (**10 GB**, el nivel gratuito; decisión 51) y topes de descarga y transacciones, con alertas por correo al 75 % y al 100 %. El tope de almacenamiento limita también la **basura retenida**: lo que se suba al bucket de firmas en compliance queda 5 años sin poder borrarse (ver 2.2). Al llegar a un tope B2 deja de responder en vez de cobrar: si la app empieza a fallar al subir o mostrar fotos o firmas, revisar primero los topes.

### 2.2 Buckets

Los tres **privados** (*Files in Bucket are: Private*) y con **cifrado en reposo SSE-B2** (*Default Encryption: Enable*, B4 de la revisión de la migración a B2; transparente para la API S3 y rclone). Nada se sirve en público: fotos y firmas solo con `temporaryUrl()` (URLs firmadas v4), respaldos solo con rclone o desde el panel. Un juego de buckets por entorno: los de producción no se comparten con local ni con staging (la app no arranca en producción si fotos y firmas comparten bucket o llave, M1).

| Bucket | Contenido | Object Lock | Lifecycle |
|---|---|---|---|
| `activosb31-fotos` | Fotos de activos (`assets/`) | No | *Keep only the last version of the file* (lo oculto se borra al día siguiente) |
| `activosb31-firmas` | Firmas de entregas (`signatures/`, RN-024) | **Activado al crear el bucket**. Retención por defecto: **governance** durante las pruebas (periodo **1 día**, decisión 49); **compliance, 1825 días** (5 años, pregunta 43) antes de la primera firma real (decisión 46) | **Ninguno** (*Keep all versions*) |
| `activosb31-respaldos` | `pg_dump` en `daily/` y `monthly/` | **Activado al crear el bucket**. Retención por defecto: **compliance, 30 días** (decisiones 47 y 52) | Reglas por prefijo: `daily/` ocultar a los **35** días y borrar lo oculto a **1** día; `monthly/` ocultar a los **365** días y borrar lo oculto a **1** día |

Notas:

- **Object Lock se activa al crear** los buckets de firmas y respaldos (opción *Object Lock: Enable*), sin depender de activarlo después.
- B2 **versiona siempre**: borrar un archivo por la API S3 (sin `versionId`) solo lo **oculta**; la versión sigue ocupando espacio (y contando para los topes) hasta que el lifecycle la borre. Por eso el lifecycle es obligatorio en fotos (cambiar o quitar una foto oculta la anterior) y en respaldos.
- **No hay retención por prefijo**: la retención por defecto aplica a todo el bucket. Por eso las firmas tienen su propio bucket (con R2 se iba a usar un *bucket lock* sobre el prefijo `signatures/` del bucket de archivos).
- **Firmas, governance → compliance (decisión 46)**: en governance, una llave con `bypassGovernance` (o la master key desde el panel) puede acortar o quitar la retención. Las pruebas del bucket de firmas usan **solo archivos sueltos** (p. ej. `signatures/humo.txt`, sección 2.6), **nunca entregas** (reales o de prueba) en la base de producción: `deliveries` es de solo inserción, así que una entrega de prueba no se podría quitar y, al limpiar su archivo, `deliveries:verify-signatures` quedaría en rojo para siempre. **Antes de la primera firma real**, borrar los archivos de prueba y cambiar la retención por defecto del bucket a **compliance, 1825 días** con la evidencia de la prueba h (sección 7). **Compliance no es reversible**: nadie (ni la cuenta ni Backblaze) puede borrar esas versiones ni acortar su retención, y el bucket no se puede eliminar mientras tenga archivos retenidos; revisar el número (**1825**, en días) y el modo antes de guardar. Los archivos subidos antes del cambio conservan la retención con la que se subieron.
- Lo que el lock **no** impide (M2): con `writeFiles` se puede **ocultar** una firma o subir otra versión con el mismo nombre (la app nunca lo hace). La versión original sigue retenida y se recupera con el runbook de abajo; `deliveries:verify-signatures` (domingos) detecta la firma faltante o alterada y lo registra en la bitácora. Cualquier archivo subido con esa llave (basura, una versión suplantada) también queda retenido **5 años** sin poder borrarse: lo acota el tope de almacenamiento (2.1).
- **Respaldos (decisión 47, riesgo aceptado)**: `daily/` y `monthly/` comparten la retención de 30 días; del día 31 al 365 un mensual podría borrarse con una llave con `deleteFiles` o desde el panel. La llave del Cron Job no tiene `deleteFiles` ni `readFiles`.

#### Runbook: recuperar una firma oculta o suplantada (M2)

Cuando `deliveries:verify-signatures` registre `signature_integrity_failed` (bitácora, `metadata.delivery_id` y `reason`), desde una máquina de confianza con la CLI `b2` autorizada con la master key (o una llave temporal con `listFiles`, `readFiles` y `writeFiles` del bucket de firmas, borrada al terminar):

```bash
# 1. Ver todas las versiones del archivo (ruta: signatures/<delivery_id>.png).
b2 ls --long --versions b2://activosb31-firmas/signatures/<delivery_id>.png

# 2a. Si la última "versión" es un ocultamiento (hide marker), desocultar:
b2 file unhide b2://activosb31-firmas/signatures/<delivery_id>.png

# 2b. Si alguien subió otra versión encima (suplantada): identificar la
#     original (la más antigua, subida en la fecha de la entrega) y copiarla
#     por su fileId para que vuelva a ser la última versión.
b2 file info b2id://<fileId-original>          # fecha, tamaño, retención
b2 file server-side-copy b2id://<fileId-original> b2://activosb31-firmas/signatures/<delivery_id>.png

# 3. Comprobar con la app (Shell de Render):
php artisan deliveries:verify-signatures
```

Comprobar la sintaxis con `b2 <comando> --help` de la versión instalada (no verificado sin cuenta). La versión suplantada no se puede borrar (queda retenida 5 años); anotar el incidente (fecha, `fileId` de la versión ajena, quién tenía la llave) y **rotar la llave de firmas** (crear otra, cambiarla en Render con redeploy y borrar la anterior).

### 2.3 Application keys

La **master key no sirve** para la API S3 y nunca va en Render. Tres *application keys*, cada una limitada a **un** bucket. El formulario del panel (*Application Keys → Add a New Application Key*) solo ofrece *Read and Write*, *Read Only* o *Write Only*; para fijar capacidades exactas (sin `deleteFiles` ni `bypassGovernance`) crearlas con la CLI oficial `b2` en una máquina de confianza, autorizada con la master key:

```bash
b2 account authorize        # pide keyID y applicationKey de la master key
b2 key create --bucket activosb31-fotos     activosb31-app-fotos     listBuckets,listFiles,readFiles,writeFiles,deleteFiles
b2 key create --bucket activosb31-firmas    activosb31-app-firmas    listBuckets,listFiles,readFiles,writeFiles
b2 key create --bucket activosb31-respaldos activosb31-cron-respaldo listBuckets,listFiles,writeFiles
```

(Comprobar la sintaxis con `b2 key create --help` de la versión instalada.) La CLI muestra el `keyID` y la `applicationKey` **una sola vez**: copiarlos directo al gestor de contraseñas y a Render.

| Llave | Bucket | Capacidades | Variables |
|---|---|---|---|
| Fotos | `activosb31-fotos` | Lectura, escritura y borrado: `listBuckets`, `listFiles`, `readFiles`, `writeFiles`, `deleteFiles` | `B2_ACCESS_KEY_ID` / `B2_SECRET_ACCESS_KEY` |
| Firmas | `activosb31-firmas` | Lectura y escritura: `listBuckets`, `listFiles`, `readFiles`, `writeFiles`. **Sin** `deleteFiles`, `bypassGovernance`, `writeFileRetentions` ni `writeBucketRetentions` | `B2_SIGNATURES_ACCESS_KEY_ID` / `B2_SIGNATURES_SECRET_ACCESS_KEY` |
| Respaldos | `activosb31-respaldos` | Solo escritura y listado (M3): `listBuckets`, `listFiles`, `writeFiles`. **Sin** `readFiles`, `deleteFiles` ni `bypassGovernance`: quien obtenga la llave del Cron Job no puede descargar los respaldos | `BACKUP_B2_KEY_ID` / `BACKUP_B2_APPLICATION_KEY` |

Ninguna llave debe tener `listAllBucketNames`, `shareFiles`, `writeFileRetentions`, `writeBucketRetentions` ni `bypassGovernance` (se comprueba con `b2 key list --long` en la prueba de humo).

**No verificado sin cuenta**: el conjunto mínimo exacto de capacidades que piden la API S3 de B2 (con `aws-sdk-php`) y rclone. `writeFiles` cubre la subida (también la subida por partes de rclone) y `readFiles` la lectura y las URLs firmadas; `listBuckets` y `listFiles` se incluyen porque rclone y la API S3 consultan el bucket y sus listados (con la llave limitada a un bucket solo ven ese). El respaldo sube con `--no-check-dest` (no consulta el destino antes de subir; los nombres llevan fecha y hora) para no necesitar `readFiles`. Si la subida falla con *AccessDenied* / *unauthorized*, repetirla con `-vv` (sin `--dump`, que mostraría cabeceras) para ver qué llamada de la API la pidió, anotarlo y solo entonces ampliar la llave con esa capacidad. Sin `deleteFiles`, si una entrega falla después de subir su firma, la limpieza de esa firma huérfana falla sin interrumpir la respuesta y se reporta en el log con la ruta (`No se pudo borrar la firma huérfana signatures/<id>.png ...`, B2 de la revisión); con el lock tampoco se podría borrar.

**Restauración de respaldos**: la llave del Cron Job no puede descargar. Descargar desde el panel de B2 con la cuenta (2FA) o con una llave **de solo lectura** (`listBuckets`, `listFiles`, `readFiles` sobre `activosb31-respaldos`) creada para el simulacro **fuera de Render** y borrada al terminar.

La app no puede leer ni borrar respaldos, el respaldo no puede leer ni tocar fotos ni firmas y la llave de fotos no puede tocar las firmas.

### 2.4 Variables de la app (Web Service y Cron Job de mantenimiento)

| Variable | Valor |
|---|---|
| `ASSETS_DISK` | `b2` (M-4: la app no arranca en producción con otro valor) |
| `SIGNATURES_DISK` | `b2_firmas` (M-4: la app no arranca en producción con otro valor) |
| `B2_REGION` | Región del endpoint, p. ej. `us-east-005` |
| `B2_ENDPOINT` | `https://s3.<región>.backblazeb2.com` (el mismo para los tres buckets) |
| `B2_BUCKET` | `activosb31-fotos` |
| `B2_ACCESS_KEY_ID` / `B2_SECRET_ACCESS_KEY` | `keyID` / `applicationKey` de la llave de fotos |
| `B2_SIGNATURES_BUCKET` | `activosb31-firmas` |
| `B2_SIGNATURES_ACCESS_KEY_ID` / `B2_SIGNATURES_SECRET_ACCESS_KEY` | `keyID` / `applicationKey` de la llave de firmas |

M1: en producción la app no arranca si falta (o está vacía) `B2_BUCKET`, `B2_ACCESS_KEY_ID`, `B2_SIGNATURES_BUCKET` o `B2_SIGNATURES_ACCESS_KEY_ID`, si `B2_SIGNATURES_BUCKET` es igual a `B2_BUCKET` o si `B2_SIGNATURES_ACCESS_KEY_ID` es igual a `B2_ACCESS_KEY_ID`. El mensaje solo nombra las variables, nunca sus valores.

Los discos `b2` y `b2_firmas` (`backend/config/filesystems.php`) usan *path-style* y `request_checksum_calculation` / `response_checksum_validation` = `when_required`: `aws-sdk-php` 3.401 manda checksums CRC32 por defecto y B2 los rechaza.

### 2.5 Variables del respaldo (Cron Job `activosb31-respaldo`)

`BACKUP_B2_BUCKET=activosb31-respaldos`, `BACKUP_B2_KEY_ID` y `BACKUP_B2_APPLICATION_KEY` (sección 5.2). Sin endpoint: rclone usa su backend nativo `b2` con `hard_delete=false` (nunca borra versiones) y `--no-check-dest`.

### 2.6 Prueba de humo (antes de cargar datos reales)

Solo con archivos sueltos de prueba (`assets/humo.txt`, `signatures/humo.txt`), **nunca creando entregas** en la base de producción (2.2). Anotar el resultado de cada paso; cualquier error de ACL (`AccessDenied` por `x-amz-acl`/visibilidad) o de `Content-MD5`/checksum al subir al bucket con Object Lock se anota: es lo que no se pudo probar sin cuenta.

Tras el primer deploy, desde el *Shell* del Web Service:

```bash
# Fotos: escribir, leer y URL firmada.
php artisan tinker --execute '$d = Storage::disk("b2"); $d->put("assets/humo.txt", "ok"); echo $d->get("assets/humo.txt"), PHP_EOL, $d->temporaryUrl("assets/humo.txt", now()->addMinutes(5)), PHP_EOL;'
# Borrar: debe imprimir bool(false) (después de los curl de abajo).
php artisan tinker --execute 'Storage::disk("b2")->delete("assets/humo.txt"); var_dump(Storage::disk("b2")->exists("assets/humo.txt"));'

# Firmas (en governance): escribir, leer, exists y URL firmada normal y de 10 s.
php artisan tinker --execute '$d = Storage::disk("b2_firmas"); $d->put("signatures/humo.txt", "ok"); echo $d->get("signatures/humo.txt"), PHP_EOL; var_dump($d->exists("signatures/humo.txt")); echo $d->temporaryUrl("signatures/humo.txt", now()->addMinutes(5)), PHP_EOL, $d->temporaryUrl("signatures/humo.txt", now()->addSeconds(10)), PHP_EOL;'
# Borrar con la llave de firmas: debe fallar (excepción) o solo ocultar; nunca borrar la versión.
php artisan tinker --execute 'try { Storage::disk("b2_firmas")->delete("signatures/humo.txt"); echo "sin excepción: comprobar que solo se ocultó", PHP_EOL; } catch (Throwable $e) { echo get_class($e), PHP_EOL; }'
```

Desde cualquier máquina:

```bash
curl -s -o /dev/null -w "%{http_code}\n" "<URL firmada de la foto>"             # 200
curl -s -o /dev/null -w "%{http_code}\n" "https://s3.<región>.backblazeb2.com/activosb31-fotos/assets/humo.txt"        # 403 (privado)
curl -s -o /dev/null -w "%{http_code}\n" "<URL firmada de la firma, 5 min>"     # 200
curl -s -o /dev/null -w "%{http_code}\n" "https://s3.<región>.backblazeb2.com/activosb31-firmas/signatures/humo.txt"  # 403 (privado)
sleep 15; curl -s -o /dev/null -w "%{http_code}\n" "<URL firmada de la firma, 10 s>"   # 403 (vencida)
```

Desde una máquina de confianza con la CLI `b2` (master key, salvo donde se indica otra llave; las llaves se leen con `read -rs`, nunca escritas en la línea de comandos):

```bash
# Configuración real de los tres buckets: allPrivate, Object Lock (firmas y respaldos),
# defaultRetention, lifecycleRules (ninguna en firmas) y defaultServerSideEncryption SSE-B2.
b2 bucket get activosb31-fotos
b2 bucket get activosb31-firmas
b2 bucket get activosb31-respaldos

# Retención real de los archivos subidos (governance en firmas; 30 días en el respaldo).
b2 file info b2://activosb31-firmas/signatures/humo.txt
b2 file info b2://activosb31-respaldos/daily/<archivo del Trigger Run>

# El borrado con la llave de firmas no borró la versión (debe seguir listada).
b2 ls --long --versions b2://activosb31-firmas/signatures/

# Capacidades: ninguna llave con listAllBucketNames, shareFiles, writeFileRetentions,
# writeBucketRetentions ni bypassGovernance; cada una limitada a su bucket.
b2 key list --long
```

Casos negativos de mínimo privilegio (autorizar la CLI con cada llave, `b2 account authorize`, y volver a la master key al terminar). Todos deben fallar con *unauthorized* / *AccessDenied*:

| Llave | Operación que debe fallar |
|---|---|
| Fotos | `b2 ls b2://activosb31-firmas` y `b2 ls b2://activosb31-respaldos` |
| Firmas | `b2 ls b2://activosb31-fotos`; `b2 rm --versions b2://activosb31-firmas/signatures/humo.txt` (sin `deleteFiles`); acortar o quitar la retención de `signatures/humo.txt` |
| Respaldos | `b2 file download b2://activosb31-respaldos/daily/<archivo> /tmp/x` (sin `readFiles`); `b2 rm` de ese archivo; `b2 ls b2://activosb31-fotos` |

Luego ejecutar a mano el Cron Job de respaldo (*Trigger Run*) y confirmar que termina bien y que aparece `daily/activosb31-<fecha>.dump` con retención de 30 días. Al terminar, borrar `signatures/humo.txt` con la master key (o esperar a que venza el día de governance).

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
| `APP_ENV` | `production` (activa la cookie `secure`, el chequeo M-4 de `ASSETS_DISK`/`SIGNATURES_DISK` y la exigencia de `ADMIN_INITIAL_PASSWORD`) |
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
| `ASSETS_DISK` | `b2` (la app no arranca en producción con otro valor, M-4) |
| `SIGNATURES_DISK` | `b2_firmas` (la app no arranca en producción con otro valor, M-4) |
| `LOCAL_DISK_SERVE` | sin definir o `false` (M-3) |
| `B2_REGION` / `B2_ENDPOINT` | Región (p. ej. `us-east-005`) y `https://s3.<región>.backblazeb2.com` (sección 2.4) |
| `B2_BUCKET` / `B2_ACCESS_KEY_ID` / `B2_SECRET_ACCESS_KEY` | `activosb31-fotos` y su llave |
| `B2_SIGNATURES_BUCKET` / `B2_SIGNATURES_ACCESS_KEY_ID` / `B2_SIGNATURES_SECRET_ACCESS_KEY` | `activosb31-firmas` y su llave (sin borrado) |
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
| Variables | El Environment Group `activosb31-app` (necesita `DB_URL`, `APP_KEY`, `ASSETS_DISK=b2`, `SIGNATURES_DISK=b2_firmas` y las variables `B2_*`: la verificación lee las firmas del bucket `activosb31-firmas`). **Nunca** `DB_MIGRATION_URL` (B1). |

- Todos los días: `sanctum:prune-expired --hours=24` borra tokens Bearer vencidos (FR-016, D-009).
- Domingos (día 7 en UTC; a las 08:00 UTC ya es domingo en Bogotá): `deliveries:verify-signatures` (M-3 de 007/008, RN-024). Si encuentra firmas alteradas o faltantes las registra en la bitácora y sale con error: el Cron Job aparece como fallido en Render (configurar la notificación por correo de fallos del servicio).

### 5.2 Respaldo de la base (`activosb31-respaldo`)

| Campo | Valor |
|---|---|
| Runtime | Docker, Root Directory `ops/backup`, Dockerfile Path `Dockerfile` |
| Imagen | `postgres:17.11-alpine3.24` + rclone 1.75.2 + `age` (1.3.2 al construir el 2026-10-18, paquete de Alpine) + `ca-certificates` (`ops/backup/Dockerfile`) |
| Schedule | `30 7 * * *` (07:30 UTC = 02:30 America/Bogota) |
| Command | vacío (usa `/usr/local/bin/backup.sh`) |

Variables (no van en el Environment Group de la app). La conexión va en las variables estándar de libpq y **no** como URL: `pg_dump --dbname=<url>` dejaría la contraseña en argv, visible en `ps` (B9). El script se niega a correr si encuentra la antigua `BACKUP_DATABASE_URL` o las de R2 (`BACKUP_BUCKET`, `BACKUP_R2_*`).

| Variable | Descripción |
|---|---|
| `PGHOST` | Host **directo** de Neon (sin `-pooler`) |
| `PGUSER` | `activosb31_backup` |
| `PGPASSWORD` | Contraseña de `activosb31_backup` (la fijada con `\password`) |
| `PGDATABASE` | `neondb` (o el nombre de la base) |
| `PGSSLMODE` | Opcional. Por defecto `verify-full` con `PGSSLROOTCERT=system` (B6: verifica certificado y nombre de host con las CA de `ca-certificates`). **Por probar en el primer deploy**; si Neon falla con `verify-full`, `require` mientras se resuelve. |
| `BACKUP_B2_BUCKET` | `activosb31-respaldos` |
| `BACKUP_B2_KEY_ID` / `BACKUP_B2_APPLICATION_KEY` | `keyID` / `applicationKey` de la llave de respaldos (sección 2.3: `listBuckets`, `listFiles`, `writeFiles`; sin `readFiles`, `deleteFiles` ni `bypassGovernance`). Sin endpoint: rclone usa la API nativa de B2. |
| `BACKUP_AGE_RECIPIENT` | Opcional (B3, **pregunta 40: el usuario eligió no cifrar (2026-10-21)**; dejarla sin definir). Una o varias claves **públicas** de age (`age1...`) separadas por espacios. Sin ella el respaldo se sube sin cifrar y el log lo advierte. |

El script (`set -eu`, `umask 077`, sin imprimir secretos):

1. `pg_dump -Fc -Z 6` del esquema completo **sin las filas** de `sessions`, `cache`, `cache_locks`, `jobs` y `failed_jobs` (`--exclude-table-data`): no hacen falta para restaurar y contienen IP, user agent y payloads (minimización, Ley 1581). Se restauran vacías.
2. Verifica el archivo con `pg_restore --list`: debe tener datos de tablas y, en particular, de `deliveries` y `security_events`.
3. Si hay `BACKUP_AGE_RECIPIENT`, cifra con `age` y borra el dump en claro antes de subir (sube `*.dump.age`).
4. Lo sube con rclone (backend nativo `b2`, `hard_delete=false`, `--no-check-dest`: no lee el destino) a `daily/activosb31-<fecha>T<hora>Z.dump[.age]` y, el día 01 (UTC), también a `monthly/`. Retención por el Object Lock por defecto del bucket (30 días) y limpieza por lifecycle (sección 2.2).

Falla (y Render lo marca) si falta una variable, si el dump, la verificación o el cifrado fallan o si B2 no responde (tiempos acotados, sin quedarse colgado). Probado en local (2026-10-18) contra la base de Sail y un servidor S3 local (`rclone serve s3`): con y sin cifrado, descifrado con la clave privada y restauración en una base vacía (mismos conteos de `deliveries` y `security_events`; `sessions` vacía). Esa prueba se hizo con el remoto S3 que se usaba para R2; con B2 el remoto es el backend nativo `b2` de rclone (2026-10-21: configuración solo por variables reconocida por rclone 1.75.2 y validaciones del script probadas en Alpine, sin subida real). La subida real a B2 (y las capacidades de la llave, sección 2.3) y `verify-full` contra Neon se prueban en el primer deploy.

**Claves de age**: generarlas **fuera** de Render, en una máquina de confianza (`age-keygen -o activosb31-respaldos.key`). En Render solo va la clave pública (`age1...`). La clave privada se guarda en el gestor de contraseñas (y una copia offline, p. ej. impresa en un sobre sellado): sin ella los respaldos cifrados no se pueden restaurar. Recomendado: dos destinatarios (dos claves de dos custodios) para no depender de una sola persona.

Restauración (simulacro de la prueba g, nunca sobre la base de producción): crear una **rama** de Neon, descargar el archivo desde el panel de B2 (*Browse Files*, cuenta con 2FA) o con rclone y una llave de solo lectura creada para eso fuera de Render y borrada al terminar (la llave del Cron Job no puede descargar, sección 2.3), y

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
- **Sin preview deployments contra el backend real**: en *Settings → Environment Variables* definir `BACKEND_URL` y `VERCEL_PROXY_SECRET` solo para *Production*. Los previews no están en `FRONTEND_URL` ni en `SANCTUM_STATEFUL_DOMAINS` (nada de comodines). Para probar un preview, usar un backend de staging con sus propios Neon y buckets de B2.

## 7. Pruebas obligatorias del primer deploy

Ninguna se puede hacer sin las cuentas; anotar el resultado de cada una antes de cargar datos reales.

- **a. Foto de 4 MB por el rewrite de Vercel**: subir una foto de activo de ~4 MB (JPEG de celular) desde la app en Vercel. El límite de cuerpo de Vercel (~4,5 MB) debe dejarla pasar; Caddy corta en 8 MB y PHP en `post_max_size=8M`. Si falla con 413 de Vercel, bajar `max:4096` de `AssetPhotoRules`.
- **b. IP real y spoofing**: con un log temporal que registre **solo** `$request->ip()` y las cabeceras `X-Forwarded-For` y `X-Real-IP` (B8: nunca todas las cabeceras ni `$request->server`, que incluyen la cookie de sesión, el token Bearer y, antes de `TrustVercelProxy`, `X-Proxy-Secret`; quitarlo después con un redeploy), comprobar que (1) por Vercel Laravel ve la IP real del cliente; (2) un `X-Real-IP` o `X-Forwarded-For` inventado por el cliente no cambia la IP (Vercel sobrescribe `X-Real-IP`; sin secreto, `TrustVercelProxy` lo ignora); (3) una petición directa a `onrender.com` sin secreto ve su propia IP. Revisar la IP de los eventos en la bitácora. Si todo cuadra, poner `VERCEL_PROXY_REQUIRED=true` (recomendado, B2; decisión del usuario) y redeploy: desde ahí una petición directa a `onrender.com` recibe 403 (salvo `/up`).
- **c. `vercel.json` con Next 16**: en un preview (contra un backend de staging) confirmar que se aplica el rewrite de `vercel.json` (en Vercel el de `next.config.js` está desactivado: si `/api` da 404, `vercel.json` no se aplicó), que llega `X-Proxy-Secret` con el valor de la variable (no el texto literal `$VERCEL_PROXY_SECRET`) y que las respuestas de `/api` no se cachean.
- **d. Suspensión de Neon (`sleep(65)`)**: dejar la base inactiva más de 5 min (o simularlo con una petición que espere 65 s) y verificar que la primera petición siguiente responde (reconexión del pooler, sin errores 500 y dentro de `max_wait_time` / `max_execution_time`).
- **e. Memoria con dos subidas simultáneas**: dos fotos grandes (24 Mpx) a la vez. Con `num_threads 2` y `memory_limit=256M` el servicio no debe reiniciarse por memoria (Render → *Metrics*); una tercera petición espera hasta 15 s y luego recibe 503.
- **f. GRANT por columna**: con la app usando `activosb31_app`, registrar una devolución (actualiza `delivery_items.returned_quantity`) y comprobar que funciona; desde `psql` con ese rol, `UPDATE delivery_items SET quantity = quantity` y `DELETE FROM security_events` deben fallar con *permission denied*. Correr las consultas de verificación del final de `roles.sql`.
- **g. Restauración de prueba**: tras el primer respaldo, restaurarlo en una rama de Neon (sección 5.2) y comprobar que la app de staging (o un `psql`) ve los datos.
- **h. Firmas en compliance antes de la primera firma real (decisión 46, A1 de la revisión de la migración a B2)**: tras la prueba de humo (2.6) y con los archivos de prueba borrados, cambiar la retención por defecto de `activosb31-firmas` a **compliance, 1825 días**. **No es reversible**: dos personas revisan modo y número antes de guardar. Evidencia que se anota: (1) salida de `b2 bucket get activosb31-firmas` con `defaultRetention` en modo `compliance` y periodo de **1825 días**, revisada por dos personas; (2) después de la primera entrega real, `b2 file info b2://activosb31-firmas/signatures/<id>.png` mostrando retención `compliance` con fecha de vencimiento a 5 años.

Además: confirmar en los logs que `max_execution_time=60` aplica con FrankenPHP (pendiente 9 de `CLAUDE.md`; en la imagen local lo reporta el `ini_get` de una petición web) y que pasa la prueba de humo de B2 (sección 2.6): `put`, `temporaryUrl` y `delete` de una foto, `put` de una firma en el bucket con Object Lock y subida del respaldo con rclone.

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
- **B3 (riesgo aceptado, pregunta 40 = no cifrar, 2026-10-21)**: los respaldos quedan sin cifrar en el bucket privado de respaldos; su protección es la llave exclusiva sin `deleteFiles`, el Object Lock de 30 días y la 2FA de Backblaze.
- **B4**: 2FA en Backblaze (y en GitHub, Render, Vercel y Neon) y retención del bucket `activosb31-firmas` en **compliance, 1825 días** antes de la primera firma real (sección 2.2, decisión 46; evidencia en la prueba h).
- **Revisión de la migración a B2 (2026-10-21)**. Aplicado en el repo: M1 (la app no arranca en producción si fotos y firmas comparten bucket o llave o si faltan), B2 (la firma huérfana que no se pudo borrar se reporta con su ruta), M3 (llave de respaldos sin `readFiles`, `--no-check-dest`), comentarios de `SIGNATURES_DISK`. Pendiente con las cuentas: SSE-B2 en los tres buckets (B4), topes y alertas (M2), prueba de humo ampliada (2.6), prueba h y confirmar el periodo de governance de las pruebas (sugerido 1 día).
- **M4 (Ley 1581, pendiente documental)**: el aviso de privacidad y la política de tratamiento deben cubrir la **firma manuscrita** y su retención de **5 años** con su base legal, los **respaldos** con datos de empleados hasta **365 días**, la **ubicación de los datos en EE. UU.** (Backblaze B2 US East; transferencia internacional) y **qué pasa al cumplir los 5 años**, contados desde la subida de la firma (decisión 50) (borrado, anonimización o nueva retención: pendiente de decidir por el usuario; en compliance el archivo no se puede borrar antes).
- **B6**: probar `sslmode=verify-full&sslrootcert=system` en `DB_URL`, `DB_MIGRATION_URL` y el respaldo (secciones 3 y 5.2).
- **B7**: cuando exista recuperación de contraseña por correo, configurar `trustHosts` (`$middleware->trustHosts(...)` en `bootstrap/app.php`) con el host del backend, para que los enlaces del correo no se puedan generar con un `Host` inyectado.
- **B8**: el log temporal de la prueba b registra solo `ip()`, `X-Forwarded-For` y `X-Real-IP`.
- **B10**: quitar `ADMIN_INITIAL_PASSWORD` con redeploy, no con *Restart* (sección 3, paso 5).
- Verificar en Neon la creación de roles sin contraseña + `\password` (sección 4).
