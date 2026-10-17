# Infraestructura y librerías verificadas

Verificado con `stack-advisor` el 2026-10-17 (fuentes: Packagist, npm, documentación oficial). Volver a verificar si pasan más de unos meses antes de fijarlas.

| Uso | Elección | Versión | Notas |
|---|---|---|---|
| Archivos (fotos, firmas) | Cloudflare R2 vía disco `s3` de Laravel + `league/flysystem-aws-s3-v3` | `^3.35.3` | `region=auto`, `endpoint=https://<ACCOUNT_ID>.r2.cloudflarestorage.com`, `use_path_style_endpoint=true`, `request_checksum_calculation`/`response_checksum_validation` = `when_required` (aws-sdk-php ≥ 3.337 calcula CRC32 por defecto). Bucket privado + `temporaryUrl()`. Probar un `put` real antes de producción. Local/tests: disco `local`. |
| PostgreSQL producción | Neon Free, `aws-us-east-1`, PG 17; Render en Virginia | — | 1 GB por proyecto, suspensión a los 5 min, PITR 6 h (no es respaldo: hace falta `pg_dump` periódico a R2). App por host `-pooler`; migraciones por conexión directa. `DB_SSLMODE=require`. |
| Excel | `maatwebsite/excel` | `^4.0.4` | Sobre PhpSpreadsheet 5 (`gd`, `zip`). La 4.x es nueva: no copiar ejemplos de 3.1 sin revisar. |
| PDF | `barryvdh/laravel-dompdf` + `dompdf/dompdf` | `^3.1.2` / `^3.1.6` | Sin Chrome. `isRemoteEnabled=false`; nunca HTML del usuario (CVE de 2026-07 corregidas en 3.1.6). Plantillas Blade con tablas y CSS inline (sin flexbox/grid/Tailwind). |
| Firma en pantalla | `signature_pad` con componente React propio | `5.1.4` | `'use client'`; al redimensionar recargar trazos con `toData`/`fromData`. Se guarda PNG. |
| Fotos | Sin librería por ahora: `image|mimes:jpeg,png,webp|max:5120|dimensions:max_width=6000,max_height=6000` | — | EXIF con GPS (Ley 1581): si se decide quitarlo, `intervention/image ^4.4` + `intervention/image-laravel ^4.1.1` con GD. |
| CI | `actions/checkout@v7`, `actions/cache@v6`, `actions/setup-node@v7`, `shivammathur/setup-php@v2` | — | Corren en Node 24. |

Decidido por el usuario (2026-10-17): respaldo diario con `pg_dump` a R2; fotos re-codificadas con Intervention Image v4 (sin EXIF, ~1600 px).
