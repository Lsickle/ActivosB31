# Implementation Plan: Usuarios, roles y matriz de permisos (RBAC)

**Branch**: `001-rbac` (trabajo sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-rbac/spec.md`

## Summary

Permisos por módulo en dos niveles (`<modulo>.ver` / `<modulo>.gestionar`, RN-009), un rol por usuario (RN-013) guardado en `users.role_id`, tres roles precargados (RN-010), matriz rol × módulo editable, gestión de usuarios y roles, y contraseña temporal con cambio obligatorio (RN-014). Backend: tablas propias + Policies/Gates nativas de Laravel (patrón EcoLink sin lo multi-tenant). Frontend: sección `/usuarios` con pestañas Usuarios/Roles/Permisos, sidebar y botones filtrados por permisos, pantalla `/cambiar-contrasena`.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13); TypeScript (Next.js 16, React 19)

**Primary Dependencies**: Sanctum 4; shadcn/ui (Base UI), TanStack Table, zod, sonner — todas ya instaladas. **Sin dependencias nuevas.**

**Storage**: PostgreSQL 17 (`roles`, `permissions`, `role_permissions`, cambios en `users`); sesiones en tabla `sessions`.

**Testing**: Pest 4 (backend, en paralelo); Vitest + Testing Library (frontend).

**Target Platform**: API en Render (Docker), web en Vercel.

**Project Type**: Aplicación web (monorepo `backend/` + `frontend/`).

**Performance Goals**: verificación de permisos con ≤ 1 consulta extra por request (memoizada).

**Constraints**: cambios de la matriz efectivos en la siguiente request (SC-003); toda escritura protegida en el servidor (FR-015).

**Scale/Scope**: decenas de usuarios, < 10 roles, 13 permisos fijos.

## Constitution Check

| Principio | Estado |
|---|---|
| I. Esquema como fuente de verdad | ✅ `data-model.md` aprobado por el usuario (rol único, `must_change_password`). `esquema-bd` se actualiza en el mismo cambio que las migraciones. |
| II. Trazabilidad | ✅ RN-009, RN-010, RN-013, RN-014, RN-015 en comentarios, nombres de test y commits. |
| III. No reinterpretación | ✅ Sin preguntas abiertas en la spec; bloqueo por intentos y recuperación por correo quedan fuera explícitamente. |
| IV. TDD | ✅ Cada tarea empieza con tests Pest/Vitest en rojo (skill `tdd`). |
| V. Revisión de seguridad | ⚠️ Obligatoria: toca auth, RBAC y contraseñas. `especialista-seguridad` revisa antes de cerrar la feature. |

Gate: **PASA** (V queda como tarea de cierre, no como violación). Re-evaluado tras Fase 1: sin cambios.

## Project Structure

### Documentation (this feature)

```text
specs/001-rbac/
├── plan.md
├── research.md          # D-001 … D-011
├── data-model.md
├── quickstart.md
├── contracts/api.md
└── tasks.md             # /speckit-tasks
```

### Source Code (repository root)

```text
backend/
├── app/
│   ├── Enums/Permission.php                         # catálogo de 13 permisos (D-002)
│   ├── Models/{Role,Permission}.php, User.php       # role(), hasPermission(), permissionCodes()
│   ├── Policies/{UserPolicy,RolePolicy}.php
│   ├── Providers/AppServiceProvider.php             # Gate::before admin + Gate::define por permiso
│   ├── Http/Middleware/EnsurePasswordIsChanged.php  # alias password.changed (D-004)
│   ├── Http/Controllers/Api/{UserController,RoleController,RolePermissionController,PermissionController,PasswordController}.php
│   ├── Http/Requests/{Store,Update}UserRequest.php, …
│   ├── Http/Resources/{UserResource,RoleResource}.php
│   └── Actions/{RevokeUserSessions,EnsureAnotherActiveAdmin}.php   # D-005, D-007
├── database/
│   ├── migrations/2026_10_17_*_create_{roles,permissions,role_permissions}_table.php
│   ├── migrations/2026_10_17_*_add_role_and_password_flags_to_users_table.php
│   └── seeders/{PermissionSeeder,RoleSeeder}.php, DatabaseSeeder.php
├── routes/{api.php,console.php}                     # sanctum:prune-expired diario (D-009)
└── tests/Feature/{Rbac,Users,Roles,Auth}/…

frontend/
├── packages/app/
│   ├── features/auth/api.ts                         # AuthUser + role, must_change_password; changePassword()
│   ├── features/usuarios/{api,schemas}.ts
│   └── provider/auth/index.tsx                      # useCan(); redirección a /cambiar-contrasena
└── apps/next/
    ├── app/cambiar-contrasena/page.tsx
    ├── app/(dashboard)/usuarios/page.tsx            # pestañas
    ├── features/usuarios/{UsersTab,UserFormDialog,RolesTab,RoleFormDialog,PermissionMatrix}.tsx (+ .test.tsx)
    ├── features/auth/ChangePasswordForm.tsx (+ .test.tsx)
    ├── components/app-sidebar.tsx                   # filtra navItems por permission
    └── config/sidebar-nav.tsx                       # permission: '<modulo>.ver'
```

**Structure Decision**: monorepo existente; backend sigue la estructura estándar de Laravel; frontend separa lógica compartida (`packages/app`) y UI web (`apps/next/features/usuarios`) según los alias de `CLAUDE.md`.

## Orden de implementación

1. Migraciones + enum + seeders + `User::hasPermission` (tests de modelo y seeder).
2. Gates/Policies + `/api/user` con permisos + middleware `password.changed` + cambio de contraseña.
3. Endpoints de usuarios (incluye invariantes último admin / uno mismo y revocación).
4. Endpoints de roles y matriz.
5. Frontend: `useCan`, sidebar, `/cambiar-contrasena`.
6. Frontend: pestañas Usuarios, Roles, Permisos.
7. `qa-test-runner`, revisión de `especialista-seguridad`, verificación en navegador (quickstart), actualización de `CLAUDE.md` (estado y pendientes de seguridad nº 3).

## Complexity Tracking

Sin violaciones que justificar.
