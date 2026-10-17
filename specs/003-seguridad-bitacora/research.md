# Research: 003-seguridad-bitacora

## D-001 — Registro explícito, no observers
- **Decision**: `RecordSecurityEvent::handle(SecurityEventType $type, ?User $actor, ?User $target = null, ?Role $role = null, array $metadata = [], ?string $loginAttempted = null)` llamado por cada controlador dentro de su `DB::transaction`. IP y user agent salen del `Request` actual.
- **Rationale**: los eventos son semánticos (cambio de rol, permisos agregados/quitados) y necesitan el actor; un observer de Eloquent no distingue "editar usuario" de "actualizar last_login_at". La misma transacción cumple FR-009.
- **Alternatives**: observers / listeners de eventos de Laravel (Login, Failed, Logout) — no cubren las acciones de administración y los de auth no se disparan con nuestro login manual.

## D-002 — Inmutabilidad en la base de datos
- **Decision**: función plpgsql `prevent_security_events_change()` + trigger `BEFORE UPDATE OR DELETE ... FOR EACH ROW` y `BEFORE TRUNCATE ... FOR EACH STATEMENT` que lanza excepción. El modelo además rechaza `update`/`delete`.
- **Alcance real** (corregido tras la revisión de seguridad): el trigger protege contra errores de código y contra cualquier intento de alterar la bitácora **a través de la aplicación** (Eloquent, query builder, SQL crudo desde Laravel). **No** protege contra quien tenga la credencial del rol dueño de la tabla: ese rol puede `ALTER TABLE ... DISABLE TRIGGER`, `DROP TRIGGER` o `DROP TABLE`. Mientras la aplicación se conecte con el rol dueño, la inmutabilidad es una salvaguarda, no una garantía.
- **Pendiente antes de datos reales**: separar dos roles de PostgreSQL: un rol dueño que solo usan las migraciones (`migrate --force` en el despliegue) y un rol de la aplicación sin propiedad de las tablas, con `GRANT SELECT, INSERT ON security_events` (sin `UPDATE`, `DELETE`, `TRUNCATE`) y los privilegios normales sobre el resto de tablas y secuencias. Neon sí permite crear roles adicionales (consola o SQL), así que la separación es viable; el trigger se mantiene como segunda capa.
- **Rationale**: el trigger cubre el caso más probable (un bug o una ruta nueva que intente editar eventos) sin depender de la configuración del proveedor. `migrate:fresh` (DROP TABLE) sigue funcionando en desarrollo y tests.

## D-003 — Contador atómico
- **Decision**: un `UPDATE users SET failed_login_attempts = CASE WHEN locked_until IS NOT NULL AND locked_until <= now() THEN 1 ELSE failed_login_attempts + 1 END, locked_until = CASE ... WHEN (nuevo valor) >= 5 THEN now() + interval '15 minutes' ... END WHERE id = ? RETURNING failed_login_attempts, locked_until`. Si el resultado bloquea, se registra `account_locked`. Mientras está bloqueada, nuevos fallos no extienden el bloqueo ni incrementan.
- **Rationale**: dos intentos simultáneos no pierden incrementos (edge case); un bloqueo vencido reinicia la cuenta.

## D-004 — Respuesta y tiempos
- **Decision**: el login siempre ejecuta `Hash::check` (ya existe el hash ficticio), luego decide; cuenta bloqueada → mismo `422 {login: ['Credenciales inválidas.']}`. El aviso de bloqueo es texto fijo de la pantalla de login, no depende de la cuenta.
- **Rationale**: FR-002 sin enumeración por mensaje ni por tiempo.

## D-005 — Qué se guarda en un login fallido
- **Decision** (corregida tras la revisión de seguridad, M-3): `login_attempted` solo se guarda en `login_failed` **cuando la cuenta no existe** (si existe, `target_user_id` ya la identifica y el texto es redundante). En ese caso se guarda el texto ingresado (recortado a 255) solo si tiene forma de usuario (tras minúsculas y `trim`: `^[a-z0-9._-]+$`, 3 a 40 caracteres, la misma regla de 001) o de correo (`FILTER_VALIDATE_EMAIL`, máx. 255); si no, se guarda el marcador `[texto no válido, N caracteres]`. Nunca la contraseña.
- **Rationale**: quien escribe la contraseña en el campo de usuario suele producir un texto que no es un usuario ni un correo válido (espacios, símbolos, longitud); el marcador evita guardarla en la bitácora y conserva la longitud como pista. Para una cuenta existente nunca se guarda el texto. Sigue siendo posible que una contraseña con forma de usuario válido quede registrada; ese riesgo residual es menor y se acepta para poder investigar ataques contra cuentas inexistentes (enumeración, spraying).

## D-006 — Consulta
- **Decision**: `GET /api/security-events?event=&user_id=&from=&to=&page=&per_page=` (25 por defecto, máx. 100); `user_id` coincide con actor **o** afectado; `from`/`to` fechas inclusivas en zona `America/Bogota`. Orden `created_at desc, id desc`.

## D-007 — Desbloqueo
- **Decision**: `POST /api/users/{user}/unlock` (UserPolicy `update`, que exige `usuarios.gestionar`): pone contador en 0 y `locked_until` en NULL; registra `account_unlocked` solo si estaba bloqueada; responde `{data: UserResource}`.
- **Complemento (A-2)**: comando `php artisan security:unlock {login}` con el mismo efecto, para cuando nadie con `usuarios.gestionar` puede entrar. Registra `account_unlocked` con `actor_id` NULL, `metadata = {"channel": "cli"}` e IP/navegador NULL. Ver el runbook al final.

## D-008 — Configuración
- **Decision**: `config('auth.lockout.max_attempts')` = 5 y `config('auth.lockout.minutes')` = 15, sin variables de entorno (RN-034 fija los valores; cambiar exige cambiar la regla).

## Runbook: desbloquear al administrador

Caso: el Administrador (o el único usuario con `usuarios.gestionar`) quedó bloqueado por intentos fallidos (RN-034) y nadie puede desbloquearlo desde la pantalla de Usuarios. Alternativa sin hacer nada: esperar los 15 minutos del bloqueo.

1. Abrir una consola con acceso a la base de datos de producción:
   - **Render**: desde el *Shell* del Web Service en el panel de Render, si el plan contratado lo ofrece (el directorio de trabajo ya es el del backend).
   - **Si el plan no ofrece shell**: desde una copia local del backend, apuntando temporalmente las variables `DB_*` a la **conexión directa** de Neon (no la del *pooler*), sin guardarlas en archivos versionados.
2. Ejecutar `php artisan security:unlock <usuario-o-correo>` (sin distinguir mayúsculas). En local con Docker: `docker compose exec laravel.test php artisan security:unlock <usuario-o-correo>`.
3. Interpretar la salida:
   - "Cuenta ... desbloqueada." (código 0): listo; la cuenta entra de inmediato y queda `account_unlocked` con canal `cli` en la bitácora.
   - "... no está bloqueada ..." (código 0): no se cambió nada; el problema es otro (contraseña olvidada, cuenta inactiva, rate limiting de 1 minuto).
   - "No existe una cuenta ..." (código distinto de 0): revisar el usuario o correo escrito.
4. Si el bloqueo se debió a un ataque, revisar los `login_failed` de la bitácora antes de volver a entrar y considerar restablecer la contraseña.
5. Si se usaron credenciales de producción en local, retirarlas de la sesión de la terminal al terminar.

## D-009 — IP del cliente detrás de proxies (A-1)
- **Decision**: `trustProxies(at: 'REMOTE_ADDR')`: se confía solo en el salto inmediato; `ip()` es el valor de `X-Forwarded-For` más a la derecha que no es de un proxy de confianza. Con `'*'` el cliente podía inventar la IP (primer valor) y evadir el rate limiting por IP.
- **Supuesto por verificar en el primer despliegue**: que Render agrega la IP real al final de `X-Forwarded-For` y que el contenedor solo es accesible a través de su proxy. Registrar temporalmente `REMOTE_ADDR`, `X-Forwarded-For` y `CF-Connecting-IP` **con y sin** el rewrite de Vercel delante. Si hay más de un salto (Cloudflare → balanceador de Render, o Vercel → Render), `ip()` sería la IP de un proxy compartida por todos (rate limit global e IP inútil en la bitácora): agregar los rangos de esos proxies a los de confianza o reenviar la IP desde Next con un secreto compartido (pendiente nº 1 de `CLAUDE.md`). Entonces restringir también los encabezados confiables a `X-Forwarded-For` y `X-Forwarded-Proto` (E-7).
