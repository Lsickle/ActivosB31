#!/bin/sh
# Respaldo de la base de datos de ActivosB31 a Backblaze B2 (decisiones 44,
# 45 y 47: bucket privado activosb31-respaldos con Object Lock).
#
# 1. pg_dump en formato custom (-Fc, compresión 6) con el rol activosb31_backup
#    (ops/neon/roles.sql, conexión directa de Neon). Sin los datos de tablas
#    efímeras (sesiones, caché, colas): no hacen falta para restaurar y
#    contienen IP, user agent y payloads (minimización, Ley 1581).
# 2. Verifica el archivo con pg_restore --list: que se pueda leer, que traiga
#    datos de tablas y, en particular, de deliveries y security_events.
# 3. Si BACKUP_AGE_RECIPIENT está definido, cifra el dump con age (B3 de la
#    revisión del despliegue) y solo sube el cifrado. Si no, advierte y sube
#    el dump sin cifrar (pregunta 40 del usuario, pendiente; recomendado sí).
# 4. Lo sube a b2:$BACKUP_B2_BUCKET/daily/ y, el día 01 (UTC), también a
#    monthly/. Retención por Object Lock por defecto del bucket (30 días,
#    decisión 47) y lifecycle (daily/ se oculta a los 35 días, monthly/ a los
#    365; lo oculto se borra al día siguiente): ver docs/despliegue.md.
#
# Variables (Render, Cron Job):
#   Conexión (B9: variables de libpq, nunca una URL en argv de pg_dump, que
#   otros procesos verían en `ps`): PGHOST (host directo, sin -pooler),
#   PGUSER (activosb31_backup), PGPASSWORD, PGDATABASE. Opcionales:
#   PGSSLMODE (por defecto verify-full) y PGSSLROOTCERT (con verify-full, por
#   defecto system: el almacén de ca-certificates de la imagen; B6, por
#   probar en Neon; si falla, PGSSLMODE=require mientras se resuelve).
#   B2: BACKUP_B2_BUCKET (activosb31-respaldos), BACKUP_B2_KEY_ID y
#   BACKUP_B2_APPLICATION_KEY (application key limitada a ese bucket con
#   listBuckets, listFiles y writeFiles: sin readFiles, deleteFiles ni
#   bypassGovernance; NUNCA la master key). Sin endpoint: rclone usa la API
#   nativa de B2. Para restaurar se usa otra llave, fuera de Render.
#   Cifrado (opcional): BACKUP_AGE_RECIPIENT, una o varias claves públicas de
#   age (age1...) separadas por espacios. La clave privada NUNCA va en Render.
# Nunca se imprimen: sin `set -x` y sin mostrar valores.

set -eu
# B9: el dump y la tabla de contenido solo los puede leer este usuario.
umask 077

if [ -n "${BACKUP_DATABASE_URL:-}" ]; then
    echo "ERROR: BACKUP_DATABASE_URL ya no se usa (la URL con contraseña quedaría en argv). Defina PGHOST, PGUSER, PGPASSWORD y PGDATABASE y borre BACKUP_DATABASE_URL." >&2
    exit 1
fi

# Decisión 44: el almacenamiento pasó de Cloudflare R2 a Backblaze B2.
for var in BACKUP_BUCKET BACKUP_R2_ACCESS_KEY_ID BACKUP_R2_SECRET_ACCESS_KEY BACKUP_R2_ENDPOINT; do
    eval "value=\${$var:-}"
    if [ -n "$value" ]; then
        echo "ERROR: $var era de Cloudflare R2 y ya no se usa. Defina BACKUP_B2_BUCKET, BACKUP_B2_KEY_ID y BACKUP_B2_APPLICATION_KEY y borre las variables BACKUP_BUCKET y BACKUP_R2_*." >&2
        exit 1
    fi
done

missing=""
for var in PGHOST PGUSER PGPASSWORD PGDATABASE BACKUP_B2_BUCKET BACKUP_B2_KEY_ID BACKUP_B2_APPLICATION_KEY; do
    eval "value=\${$var:-}"
    if [ -z "$value" ]; then
        missing="$missing $var"
    fi
done
if [ -n "$missing" ]; then
    echo "ERROR: faltan variables de entorno:$missing" >&2
    exit 1
fi

# B6: verificar el certificado y el nombre del servidor, no solo cifrar.
export PGSSLMODE="${PGSSLMODE:-verify-full}"
# libpq solo admite sslrootcert=system con verify-full (con otro modo falla
# con "weak sslmode"): el valor por defecto se pone solo en ese caso.
if [ "$PGSSLMODE" = "verify-full" ]; then
    export PGSSLROOTCERT="${PGSSLROOTCERT:-system}"
fi
# Sin ~/.pgpass ni servicios de pg_service.conf que cambien el destino.
export PGPASSFILE=/dev/null
export PGSERVICEFILE=/dev/null

# Remoto "b2" de rclone (backend nativo de Backblaze B2) solo por variables
# de entorno, sin archivo de configuración. hard_delete=false (el valor por
# defecto, explícito): rclone nunca borra versiones; la limpieza la hace el
# lifecycle del bucket y el Object Lock impide borrar antes de 30 días.
export RCLONE_CONFIG_B2_TYPE=b2
export RCLONE_CONFIG_B2_ACCOUNT="$BACKUP_B2_KEY_ID"
export RCLONE_CONFIG_B2_KEY="$BACKUP_B2_APPLICATION_KEY"
export RCLONE_CONFIG_B2_HARD_DELETE=false
# Sin archivo de configuración; tiempos acotados para que un B2 caído haga
# fallar el Cron Job (y se note) en vez de quedarse colgado.
# M3 (revisión de la migración a B2): la llave no tiene readFiles (ni
# deleteFiles). --no-check-dest sube sin consultar antes el destino: los
# nombres llevan fecha y hora, nunca se reemplaza nada. La verificación del
# respaldo (pg_restore --list) es sobre el archivo local, antes de subir; la
# comprobación posterior de rclone usa el SHA-1 que devuelve la subida.
RCLONE_FLAGS="--config= --no-check-dest --contimeout 15s --timeout 120s --retries 3 --low-level-retries 3 --stats-log-level NOTICE"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
name="activosb31-${timestamp}.dump"
workdir="$(mktemp -d)"
file="${workdir}/${name}"
trap 'rm -rf "$workdir"' EXIT

echo "Respaldo ${name}: pg_dump..."
# Esquema completo; sin filas de tablas efímeras (se restauran vacías).
pg_dump --format=custom --compress=6 --file="$file" \
    --exclude-table-data=public.sessions \
    --exclude-table-data=public.cache \
    --exclude-table-data=public.cache_locks \
    --exclude-table-data=public.jobs \
    --exclude-table-data=public.failed_jobs

echo "Verificando con pg_restore --list..."
toc="${workdir}/toc.txt"
pg_restore --list "$file" > "$toc"
tables_with_data="$(grep -c ' TABLE DATA ' "$toc" || true)"
if [ "$tables_with_data" -eq 0 ]; then
    echo "ERROR: el respaldo no contiene datos de ninguna tabla." >&2
    exit 1
fi
for table in deliveries security_events; do
    if ! grep -q " TABLE DATA public ${table} " "$toc"; then
        echo "ERROR: el respaldo no contiene los datos de ${table}." >&2
        exit 1
    fi
done
size="$(wc -c < "$file" | tr -d ' ')"
echo "OK: ${tables_with_data} tablas con datos (incluye deliveries y security_events), ${size} bytes."

upload="$file"
upload_name="$name"
if [ -n "${BACKUP_AGE_RECIPIENT:-}" ]; then
    echo "Cifrando con age..."
    recipients=""
    for recipient in $BACKUP_AGE_RECIPIENT; do
        recipients="$recipients -r $recipient"
    done
    upload="${file}.age"
    upload_name="${name}.age"
    # shellcheck disable=SC2086 # cada clave pública es un argumento -r propio
    age $recipients -o "$upload" "$file"
    rm -f "$file"
    echo "OK: cifrado ($(wc -c < "$upload" | tr -d ' ') bytes)."
else
    echo "ADVERTENCIA: BACKUP_AGE_RECIPIENT no está definido; el respaldo se sube SIN cifrar (contiene datos personales de empleados, Ley 1581). Ver docs/despliegue.md, sección 5.2." >&2
fi

echo "Subiendo a daily/..."
rclone copyto "$upload" "b2:${BACKUP_B2_BUCKET}/daily/${upload_name}" $RCLONE_FLAGS

if [ "$(date -u +%d)" = "01" ]; then
    echo "Día 01: subiendo también a monthly/..."
    rclone copyto "$upload" "b2:${BACKUP_B2_BUCKET}/monthly/${upload_name}" $RCLONE_FLAGS
fi

echo "Respaldo ${upload_name} terminado."
