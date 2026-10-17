# Tasks: Usuarios, roles y matriz de permisos (RBAC)

**Input**: `specs/001-rbac/` (plan.md, spec.md, data-model.md, contracts/api.md, research.md, quickstart.md)

**Tests**: obligatorios (constitución, principio IV — TDD). En cada tarea de test, escribir el test, verlo **fallar** y luego implementar.

Rutas: backend en `backend/`, frontend en `frontend/`. Comandos: `docker compose exec laravel.test composer test` (desde `backend/`), `yarn test` / `yarn typecheck` (desde `frontend/`).

## Phase 1: Setup

- [x] T001 Crear helpers de test `actingAsRole(string $code)` y `actingWithPermissions(array $codes)` (D-011) en backend/tests/Pest.php, junto con `seedRbac()` que ejecuta PermissionSeeder y RoleSeeder

---

## Phase 2: Foundational (bloquea todas las historias)

- [x] T002 [P] Test del catálogo de permisos (13 casos, `module()`, `action()`, `label()`, reportes sin gestionar) en backend/tests/Unit/PermissionEnumTest.php
- [x] T003 Crear enum `App\Enums\Permission` (D-002) en backend/app/Enums/Permission.php
- [x] T004 Migraciones `roles`, `permissions`, `role_permissions` según data-model.md en backend/database/migrations/2026_10_17_200000_create_roles_table.php, …_200001_create_permissions_table.php, …_200002_create_role_permissions_table.php
- [x] T005 Migración que agrega `role_id` (nullable → asigna rol `admin` a usuarios existentes creándolo si falta → NOT NULL), `must_change_password`, `email` nullable, índices únicos `lower(username)` y `lower(email) WHERE email IS NOT NULL`, CHECK sin `@` en backend/database/migrations/2026_10_17_200003_add_role_and_password_flags_to_users_table.php
- [x] T006 [P] Modelos `Role` (relaciones `users`, `permissions`; scope `active`) y `Permission` con factories en backend/app/Models/Role.php, backend/app/Models/Permission.php, backend/database/factories/{RoleFactory,PermissionFactory}.php
- [x] T007 Test de `User`: `role()`, `isAdmin()`, `hasPermission()`, `permissionCodes()` (admin = los 13; rol inactivo = ninguno; memoización por instancia), normalización a minúsculas de username/email en backend/tests/Feature/Rbac/UserPermissionsTest.php
- [x] T008 Implementar en `User` la relación `role`, `isAdmin()`, `hasPermission()`, `permissionCodes()` memoizado, mutators a minúsculas, casts y fillable; `UserFactory` con `role_id` (Role::factory) y estados `admin()`/`mustChangePassword()` en backend/app/Models/User.php y backend/database/factories/UserFactory.php
- [x] T009 Test de seeders: idempotencia, permisos iniciales de Almacenista y Consulta (FR-003), Administrador re-sincronizado, no pisa cambios hechos a Almacenista, usuario `admin` queda con rol `admin` (FR-004) en backend/tests/Feature/Rbac/RbacSeederTest.php (ajustar backend/tests/Feature/Auth/AdminSeederTest.php si cambia)
- [x] T010 Seeders `PermissionSeeder` y `RoleSeeder` y orden en `DatabaseSeeder` (el admin se crea con rol `admin`) en backend/database/seeders/
- [x] T011 Test de autorización: `Gate::before` para admin, `Gate::allows('<codigo>')` según rol, permiso desconocido denegado en backend/tests/Feature/Rbac/GateTest.php
- [x] T012 Registrar `Gate::before` y `Gate::define` por cada caso del enum (D-003) en backend/app/Providers/AppServiceProvider.php
- [x] T013 Test `/api/user` y `/api/login` devuelven `role`, `permissions` efectivos y `must_change_password` (contrato) en backend/tests/Feature/Auth/SessionTest.php
- [x] T014 Actualizar `userPayload()` en backend/app/Http/Controllers/Api/AuthController.php (quitar comentario obsoleto de "Orden de arranque")
- [x] T015 [P] Actualizar tipo `AuthUser` (role, must_change_password) y agregar `useCan(code)` con test en frontend/packages/app/features/auth/api.ts, frontend/packages/app/provider/auth/index.tsx, frontend/packages/app/provider/auth/useCan.test.tsx
- [x] T016 Actualizar skill `esquema-bd` con las tablas creadas en .claude/skills/esquema-bd/SKILL.md

**Checkpoint**: migran y siembran; `/api/user` trae permisos reales.

---

## Phase 3: User Story 3 — Cambio de contraseña (P1)

**Goal**: RN-014 cumplido en servidor y UI. **Independent test**: usuario con `must_change_password` solo puede cambiar contraseña o salir.

- [x] T017 [US3] Test del middleware `password.changed`: 403 `password_change_required` en rutas protegidas; permitido `GET /api/user`, `PUT /api/user/password`, `POST /api/logout` en backend/tests/Feature/Auth/PasswordChangeRequiredTest.php
- [x] T018 [US3] Middleware `EnsurePasswordIsChanged`, alias `password.changed` en backend/bootstrap/app.php y aplicado al grupo autenticado en backend/routes/api.php
- [x] T019 [US3] Test `PUT /api/user/password`: contraseña actual errada, política (10, letras y números), igual a la actual, confirmación; éxito limpia el flag y revoca otras sesiones y tokens conservando la actual en backend/tests/Feature/Auth/ChangePasswordTest.php
- [x] T020 [US3] Acción `RevokeUserSessions` (D-005) en backend/app/Actions/RevokeUserSessions.php y `PasswordController@update` + `ChangePasswordRequest` en backend/app/Http/Controllers/Api/PasswordController.php, backend/app/Http/Requests/ChangePasswordRequest.php
- [x] T021 [P] [US3] Test y pantalla `/cambiar-contrasena` (formulario, errores 422, redirección al tablero al terminar) en frontend/apps/next/features/auth/ChangePasswordForm.tsx(.test.tsx), frontend/apps/next/app/cambiar-contrasena/page.tsx, frontend/packages/app/features/auth/{api,schemas}.ts
- [x] T022 [US3] Redirigir a `/cambiar-contrasena` cuando `user.must_change_password` (en `useRequireAuth` y tras login) con test en frontend/packages/app/provider/auth/index.tsx y frontend/apps/next/features/auth/LoginForm.tsx

---

## Phase 4: User Story 2 — Gestión de usuarios (P1)

**Goal**: CRUD de usuarios sin borrado. **Independent test**: crear usuario Consulta y entrar con él.

- [x] T023 [US2] Test `UserController` index/show (filtros search, role_id, is_active; paginación; 403 sin `usuarios.ver`) en backend/tests/Feature/Users/ListUsersTest.php
- [x] T024 [US2] Test store/update: validaciones (duplicados sin mayúsculas, `@` en username, email opcional, rol inactivo), `must_change_password=true` al crear, 403 sin `usuarios.gestionar` en backend/tests/Feature/Users/ManageUsersTest.php
- [x] T025 [US2] Test status y reset de contraseña: revoca sesiones/tokens, 409 último admin (desactivar y cambio de rol), 422/409 desactivarse a sí mismo, reset deja `must_change_password=true` en backend/tests/Feature/Users/UserStatusAndPasswordTest.php
- [x] T026 [US2] `UserPolicy` (viewAny, view, create, update, updateStatus, resetPassword) en backend/app/Policies/UserPolicy.php
- [x] T027 [US2] Acción `EnsureAnotherActiveAdmin` con `lockForUpdate` (D-007) en backend/app/Actions/EnsureAnotherActiveAdmin.php
- [x] T028 [US2] `UserController` + `StoreUserRequest`, `UpdateUserRequest`, `UpdateUserStatusRequest`, `ResetUserPasswordRequest` + `UserResource` + rutas en backend/app/Http/Controllers/Api/UserController.php, backend/app/Http/Requests/, backend/app/Http/Resources/UserResource.php, backend/routes/api.php
- [x] T029 [P] [US2] API y schemas zod de usuarios en frontend/packages/app/features/usuarios/{api,schemas}.ts
- [x] T030 [US2] Pestaña Usuarios (TanStack Table con búsqueda y filtros, diálogo crear/editar, activar/desactivar, restablecer contraseña; acciones solo con `usuarios.gestionar`) con tests en frontend/apps/next/features/usuarios/{UsersTab,UserFormDialog,ResetPasswordDialog}.tsx(.test.tsx)
- [x] T031 [US2] Página `/usuarios` con pestañas (Usuarios, Roles, Permisos) protegida con `useRequireAuth('usuarios.ver')` en frontend/apps/next/app/(dashboard)/usuarios/page.tsx

---

## Phase 5: User Story 1 — Matriz de permisos (P1)

**Goal**: editar permisos por rol. **Independent test**: cambiar una casilla de Consulta y verificar acceso.

- [x] T032 [US1] Test `GET /api/permissions` (agrupado por módulo) y `GET /api/roles` (con permissions y users_count) en backend/tests/Feature/Roles/PermissionCatalogTest.php
- [x] T033 [US1] Test `PUT /api/roles/{role}/permissions`: reemplazo, normalización gestionar ⇒ ver, código desconocido 422, rol admin 403, 403 sin `usuarios.gestionar`, efecto inmediato en la siguiente request (SC-003) en backend/tests/Feature/Roles/RolePermissionsTest.php
- [x] T034 [US1] `RolePolicy`, `PermissionController@index`, `RoleController@index`, `RolePermissionController@update`, `RoleResource` y rutas en backend/app/Policies/RolePolicy.php, backend/app/Http/Controllers/Api/, backend/app/Http/Resources/RoleResource.php, backend/routes/api.php
- [x] T035 [P] [US1] API de roles/permisos en frontend/packages/app/features/usuarios/api.ts
- [x] T036 [US1] Componente `PermissionMatrix` (filas módulos, columnas roles, casillas Ver/Gestionar con regla de dependencia, columna Administrador bloqueada, guardado por rol con toast, solo lectura sin gestionar) con tests en frontend/apps/next/features/usuarios/PermissionMatrix.tsx(.test.tsx)

---

## Phase 6: User Story 5 — La interfaz respeta los permisos (P1)

**Goal**: menú y acciones filtradas. **Independent test**: Consulta no ve botones de gestión ni módulos sin `ver`.

- [x] T037 [US5] Declarar `permission: '<modulo>.ver'` en cada ítem y filtrar en el sidebar, con test en frontend/apps/next/config/sidebar-nav.tsx, frontend/apps/next/components/app-sidebar.tsx(.test.tsx)
- [x] T038 [US5] Placeholders de módulos protegidos con `useRequireAuth('<modulo>.ver')` y mensaje "Acceso denegado" en vez de redirección silenciosa, con test en frontend/apps/next/components/module-placeholder.tsx y frontend/packages/app/provider/auth/index.tsx

---

## Phase 7: User Story 4 — Administrar roles (P2)

**Goal**: crear/editar/desactivar roles. **Independent test**: crear "Comandante" y asignarlo.

- [x] T039 [US4] Test store/update/status de roles: nombre único sin mayúsculas, rol nuevo sin permisos, 409 al desactivar `is_system` o con usuarios activos, 403 sin gestionar en backend/tests/Feature/Roles/ManageRolesTest.php
- [x] T040 [US4] `RoleController@store/update/updateStatus` + requests + rutas en backend/app/Http/Controllers/Api/RoleController.php, backend/app/Http/Requests/, backend/routes/api.php
- [x] T041 [US4] Pestaña Roles (tabla, diálogo crear/editar, activar/desactivar) con tests en frontend/apps/next/features/usuarios/{RolesTab,RoleFormDialog}.tsx(.test.tsx)

---

## Phase 8: Polish

- [x] T042 Programar `sanctum:prune-expired --hours=24` diario (D-009) en backend/routes/console.php con test de schedule en backend/tests/Feature/Rbac/ScheduleTest.php
- [x] T043 Pint (`vendor/bin/pint --dirty`), `yarn typecheck`, suites completas vía `qa-test-runner`
- [x] T044 Revisión de `especialista-seguridad` (auth, RBAC, contraseñas, revocación) y aplicar hallazgos
- [x] T045 Verificación en navegador según specs/001-rbac/quickstart.md
- [x] T046 Actualizar CLAUDE.md (estado actual, pendiente de seguridad nº 3, cron de Render) y docs/modulos.md

## Dependencies

- Phase 2 bloquea todo. US3 → antes de US2 (US2 reutiliza `RevokeUserSessions`). US1 y US4 comparten `RoleController`/`RolePolicy` (US1 crea index; US4 agrega escritura). US5 depende solo de Phase 2 (T015).
- Orden: Setup → Foundational → US3 → US2 → US1 → US5 → US4 → Polish.

## Parallel opportunities

- T002/T006, T015/T016 dentro de Phase 2; trabajo de frontend [P] (T021, T029, T035) en paralelo con el backend de su misma historia una vez fijado el contrato.

## Implementation strategy

MVP = Phase 2 + US3 + US2 + US5 (usuarios con roles precargados y UI filtrada). La matriz (US1) y los roles adicionales (US4) completan el alcance. Commits por historia con scope `usuarios` y los RN en el mensaje.
