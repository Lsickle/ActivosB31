# Quickstart: 003-seguridad-bitacora

1. **Bloqueo** — Con una cuenta de prueba, fallar 5 veces: el 6.º intento con la contraseña correcta muestra "Credenciales inválidas.". En Usuarios aparece "Bloqueada hasta HH:MM".
2. **Desbloqueo** — Como Administrador, "Desbloquear" → la cuenta entra de inmediato.
3. **Bitácora** — Usuarios y roles → Bitácora: aparecen los fallos, el bloqueo, el desbloqueo y el ingreso. Cambiar un permiso en la matriz → evento con permisos agregados/quitados. Filtrar por usuario y por fecha.
4. **Permisos** — Como Almacenista o Consulta no aparece la pestaña; `GET /api/security-events` → 403.
5. **Inmutable** — `UPDATE security_events SET event='x'` en psql → error del trigger.
