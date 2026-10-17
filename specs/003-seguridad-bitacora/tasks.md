# Tasks: Bitácora de seguridad y bloqueo (003)

**Tests**: obligatorios (TDD). Bloqueado hasta aprobar el bloque 003 de `esquema-bd`.

## Phase 1: Fundamentos
- [x] T001 Mover el bloque 003 de "PROPUESTA" a "migrado" en `.claude/skills/esquema-bd/SKILL.md`
- [x] T002 Tests de inmutabilidad (modelo y SQL directo: UPDATE, DELETE, TRUNCATE rechazados) en `backend/tests/Feature/Seguridad/SecurityEventsImmutableTest.php`
- [x] T003 Migraciones `2026_10_17_400000_add_lockout_columns_to_users_table.php` y `2026_10_17_400001_create_security_events_table.php` (con trigger, D-002)
- [x] T004 `app/Enums/SecurityEventType.php`, `app/Models/SecurityEvent.php`, `app/Actions/RecordSecurityEvent.php`, `config/auth.php` lockout (D-008)

## Phase 2: US1 — Bloqueo (P1)
- [x] T005 [US1] Tests RN-034 en `tests/Feature/Seguridad/LockoutTest.php` (5.º fallo bloquea, correcta rechazada con mismo mensaje, vencimiento reinicia, éxito reinicia, fallos no seguidos, inactiva cuenta, inexistente no bloquea, token móvil, fallos durante bloqueo no extienden)
- [x] T006 [US1] `app/Actions/RegisterFailedLogin.php` (D-003) e integración en `AuthController::login`
- [x] T007 [US1] Tests y endpoint `POST /api/users/{user}/unlock` (`UserLockController`), `locked_until` en `UserResource`

## Phase 3: US2 — Registro de eventos (P1)
- [x] T008 [US2] Tests por evento (FR-005) en `tests/Feature/Seguridad/SecurityEventsLoggingTest.php`, incluidos "no se registra si la validación falla", "nunca guarda contraseñas" e IP/user agent
- [x] T009 [US2] Registrar eventos en `AuthController`, `PasswordController`, `UserController`, `UserLockController`, `RoleController`, `RolePermissionController` dentro de sus transacciones

## Phase 4: US3 — Consulta (P2)
- [x] T010 [US3] Tests de `GET /api/security-events` y `/types` (403 para Almacenista/Consulta, filtros, orden, paginación) en `tests/Feature/Seguridad/SecurityEventsIndexTest.php`
- [x] T011 [US3] `SecurityEventPolicy`, `SecurityEventController`, `SecurityEventResource`, rutas
- [x] T012 [P] [US3] Frontend: `packages/app/features/usuarios/api.ts` (tipos y llamadas) + `SecurityLogTab.tsx` (+ test) + pestaña en `UsuariosScreen.tsx` solo con `usuarios.gestionar`
- [x] T013 [P] [US1] Frontend: insignia "Bloqueada hasta HH:MM" y acción "Desbloquear" en `UsersTab.tsx` (+ test); aviso fijo en el login (+ test)

## Phase 5: Cierre
- [ ] T014 Pint, `yarn typecheck`, suites completas
- [ ] T015 Revisión de `especialista-seguridad` y correcciones
- [ ] T016 Verificación en navegador (quickstart) y `CLAUDE.md` (pendiente de seguridad nº 3)
