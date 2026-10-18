# Infraestructura y librerías verificadas

Verificado con `stack-advisor` el 2026-10-17 (fuentes: Packagist, npm, documentación oficial). Volver a verificar si pasan más de unos meses antes de fijarlas.

| Uso | Elección | Versión | Notas |
|---|---|---|---|
| Archivos (fotos, firmas) | Cloudflare R2 vía disco `s3` de Laravel + `league/flysystem-aws-s3-v3` | `^3.35.3` | `region=auto`, `endpoint=https://<ACCOUNT_ID>.r2.cloudflarestorage.com`, `use_path_style_endpoint=true`, `request_checksum_calculation`/`response_checksum_validation` = `when_required` (aws-sdk-php ≥ 3.337 calcula CRC32 por defecto). Bucket privado + `temporaryUrl()`. Probar un `put` real antes de producción. Local/tests: disco `local`. |
| PostgreSQL producción | Neon Free, `aws-us-east-1`, PG 17; Render en Virginia | — | 1 GB por proyecto, suspensión a los 5 min, PITR 6 h (no es respaldo: hace falta `pg_dump` periódico a R2). App por host `-pooler`; migraciones por conexión directa. `DB_SSLMODE=require`. |
| Excel | `maatwebsite/excel` | `^4.0.4` | Sobre PhpSpreadsheet 5 (`gd`, `zip`). La 4.x es nueva: no copiar ejemplos de 3.1 sin revisar. |
| PDF | `barryvdh/laravel-dompdf` + `dompdf/dompdf` | `^3.1.2` / `^3.1.6` | Sin Chrome. `isRemoteEnabled=false`; nunca HTML del usuario (CVE de 2026-07 corregidas en 3.1.6). Plantillas Blade con tablas y CSS inline (sin flexbox/grid/Tailwind). |
| Firma en pantalla | `signature_pad` con componente React propio | `5.1.4` | `'use client'`; al redimensionar recargar trazos con `toData`/`fromData`. Se guarda PNG. |
| Fotos | `intervention/image ^4.4` con GD (JPEG, PNG, WebP): `image|mimes:jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000` + máx. 24 megapíxeles (`AssetPhotoRules`) | — | Re-codificadas a JPEG ≤ 1600 px sin EXIF (Ley 1581). 4 MB por el límite de cuerpo del proxy de Vercel (~4,5 MB). En producción GD necesita WebP (`libwebp-dev`, `--with-webp`) y `memory_limit=256M` (`backend/docker/php-production.ini`). |
| Servidor de producción | FrankenPHP en modo clásico (sin Octane), imagen `dunglas/frankenphp:1.13.1-php8.4-trixie` | `1.13.1` | `backend/Dockerfile.render` + `backend/docker/Caddyfile`: `num_threads 2`, `max_wait_time 15s`, `request_body max_size 8MB`, usuario uid 10001, `GOMEMLIMIT=450MiB` (Render Starter, 512 MB). Extensiones con `install-php-extensions`; el build falla si GD no tiene WebP, JPEG o FreeType. Sin migrate/seed al arrancar (Pre-Deploy de Render). Guía: `docs/despliegue.md`. |
| Respaldo | `postgres:17.11-alpine3.24` (`pg_dump`/`pg_restore` 17.11) + `rclone` 1.75.2 (binario de `rclone/rclone:1.75.2`, `/usr/local/bin/rclone`) | — | `ops/backup/`: Cron Job de Render, `pg_dump -Fc -Z 6` diario a R2 (`daily/`, y `monthly/` el día 01), bucket y token propios. |
| CI | `actions/checkout@v7`, `actions/cache@v6`, `actions/setup-node@v7`, `shivammathur/setup-php@v2` | — | Corren en Node 24. |

Decidido por el usuario (2026-10-17): respaldo diario con `pg_dump` a R2; fotos re-codificadas con Intervention Image v4 (sin EXIF, ~1600 px).

## Pendiente antes del primer deploy

Paso a paso completo (Neon, R2, Render, Cron Jobs, Vercel y pruebas obligatorias): `docs/despliegue.md`.

- **R2 (fotos)**: el token de la app (`R2_ACCESS_KEY_ID`/`R2_SECRET_ACCESS_KEY`) limitado al bucket de fotos con permiso *Object Read & Write* (nada de tokens de cuenta ni de administración). Los respaldos (`pg_dump`) van a **otro bucket con otro token**: la app no puede leer ni borrar respaldos. El bucket de fotos **sin acceso público** (sin dominio `r2.dev` ni dominio propio): las fotos se sirven solo con `temporaryUrl()`.
- **Producción**: `ASSETS_DISK=r2` (la app no arranca en producción con otro disco, M-4) y `LOCAL_DISK_SERVE` sin definir o en `false` (M-3).
- **CI**: `composer audit` corre en el job de backend (`.github/workflows/ci.yml`); un aviso de seguridad en una dependencia rompe el CI.
