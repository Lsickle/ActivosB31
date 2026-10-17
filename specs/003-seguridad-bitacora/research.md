# Research: 003-seguridad-bitacora

## D-001 — Registro explícito, no observers
- **Decision**: `RecordSecurityEvent::handle(SecurityEventType $type, ?User $actor, ?User $target = null, ?Role $role = null, array $metadata = [], ?string $loginAttempted = null)` llamado por cada controlador dentro de su `DB::transaction`. IP y user agent salen del `Request` actual.
- **Rationale**: los eventos son semánticos (cambio de rol, permisos agregados/quitados) y necesitan el actor; un observer de Eloquent no distingue "editar usuario" de "actualizar last_login_at". La misma transacción cumple FR-009.
- **Alternatives**: observers / listeners de eventos de Laravel (Login, Failed, Logout) — no cubren las acciones de administración y los de auth no se disparan con nuestro login manual.

## D-002 — Inmutabilidad en la base de datos
- **Decision**: función plpgsql `prevent_security_events_change()` + trigger `BEFORE UPDATE OR DELETE ... FOR EACH ROW` y `BEFORE TRUNCATE ... FOR EACH STATEMENT` que lanza excepción. El modelo además rechaza `update`/`delete`.
- **Rationale**: FR-007 exige que ni una consulta directa del usuario de la aplicación pueda alterarla; revocar privilegios con GRANT no es viable en Neon/Render con un solo rol. `migrate:fresh` (DROP TABLE) sigue funcionando en desarrollo y tests.

## D-003 — Contador atómico
- **Decision**: un `UPDATE users SET failed_login_attempts = CASE WHEN locked_until IS NOT NULL AND locked_until <= now() THEN 1 ELSE failed_login_attempts + 1 END, locked_until = CASE ... WHEN (nuevo valor) >= 5 THEN now() + interval '15 minutes' ... END WHERE id = ? RETURNING failed_login_attempts, locked_until`. Si el resultado bloquea, se registra `account_locked`. Mientras está bloqueada, nuevos fallos no extienden el bloqueo ni incrementan.
- **Rationale**: dos intentos simultáneos no pierden incrementos (edge case); un bloqueo vencido reinicia la cuenta.

## D-004 — Respuesta y tiempos
- **Decision**: el login siempre ejecuta `Hash::check` (ya existe el hash ficticio), luego decide; cuenta bloqueada → mismo `422 {login: ['Credenciales inválidas.']}`. El aviso de bloqueo es texto fijo de la pantalla de login, no depende de la cuenta.
- **Rationale**: FR-002 sin enumeración por mensaje ni por tiempo.

## D-005 — Qué se guarda en un login fallido
- **Decision**: `login_attempted` = texto ingresado en "usuario o correo", recortado a 255, solo en `login_failed`. Nunca la contraseña.
- **Riesgo conocido**: si alguien escribe su contraseña en el campo de usuario, queda en la bitácora (visible solo al Administrador). Se acepta: es la práctica estándar y sin ese dato no se pueden investigar ataques. Se señala en la revisión de seguridad.

## D-006 — Consulta
- **Decision**: `GET /api/security-events?event=&user_id=&from=&to=&page=&per_page=` (25 por defecto, máx. 100); `user_id` coincide con actor **o** afectado; `from`/`to` fechas inclusivas en zona `America/Bogota`. Orden `created_at desc, id desc`.

## D-007 — Desbloqueo
- **Decision**: `POST /api/users/{user}/unlock` (UserPolicy `update`, que exige `usuarios.gestionar`): pone contador en 0 y `locked_until` en NULL; registra `account_unlocked` solo si estaba bloqueada; responde `{data: UserResource}`.

## D-008 — Configuración
- **Decision**: `config('auth.lockout.max_attempts')` = 5 y `config('auth.lockout.minutes')` = 15, sin variables de entorno (RN-034 fija los valores; cambiar exige cambiar la regla).
