# Implementation Plan: Bitácora de seguridad y bloqueo por intentos fallidos

**Branch**: `003-seguridad-bitacora` (trabajo sobre `main`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

## Summary

Tabla `security_events` de solo inserción (trigger de PostgreSQL que rechaza UPDATE/DELETE/TRUNCATE), una acción `RecordSecurityEvent` invocada explícitamente dentro de la misma transacción de cada operación auditada, y bloqueo por cuenta (`users.failed_login_attempts`, `users.locked_until`) con incremento atómico: 5 fallos seguidos → 15 minutos (RN-034). Endpoint de consulta y de desbloqueo restringidos a `usuarios.gestionar` (RN-019). Frontend: pestaña "Bitácora" en Usuarios y roles, insignia "Bloqueada" y acción "Desbloquear" en Usuarios, aviso fijo en el login.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 13); TypeScript (Next.js 16, React 19). **Sin dependencias nuevas.**

**Storage**: PostgreSQL 17 — `security_events` (nueva), `users` (+2 columnas). Esquema propuesto en `esquema-bd` (bloque 003).

**Testing**: Pest 4; Vitest + Testing Library.

**Constraints**: un evento por acción completada (FR-009) → mismo `DB::transaction`; contraseñas y tokens nunca se guardan (FR-006); mensaje genérico idéntico para cuenta bloqueada (FR-002); tiempo de respuesta igual (se sigue verificando el hash).

## Constitution Check

| Principio | Estado |
|---|---|
| I. Esquema | ⚠️ Bloque 003 de `esquema-bd` **pendiente de aprobación** (pregunta 17). No se migra antes. |
| II. Trazabilidad | ✅ RN-034, A-1, RN-019. |
| III. No reinterpretación | ✅ Umbrales de RN-034. Decisiones menores en research.md. |
| IV. TDD | ✅ |
| V. Seguridad | ⚠️ Obligatoria: autenticación y datos personales (IP). `especialista-seguridad` revisa antes de cerrar. |

## Project Structure

```text
backend/
├── app/Enums/SecurityEventType.php                    # catálogo de eventos (FR-005) con label()
├── app/Models/SecurityEvent.php                       # sin updated_at; save() de existentes y delete() lanzan excepción
├── app/Actions/RecordSecurityEvent.php                # actor, target, ip, user_agent (≤255), metadata
├── app/Actions/RegisterFailedLogin.php                # incremento atómico + bloqueo (D-003)
├── app/Policies/SecurityEventPolicy.php               # viewAny → usuarios.gestionar
├── app/Http/Controllers/Api/{SecurityEventController,UserLockController}.php
├── app/Http/Controllers/Api/{Auth,Password,User,Role,RolePermission}Controller.php   # registran eventos
├── app/Http/Resources/{SecurityEventResource,UserResource}.php                     # UserResource + locked_until
├── config/auth.php                                    # 'lockout' => ['max_attempts' => 5, 'minutes' => 15]
├── database/migrations/2026_10_17_400000_add_lockout_columns_to_users_table.php
├── database/migrations/2026_10_17_400001_create_security_events_table.php          # + trigger inmutable
└── tests/Feature/Seguridad/{Lockout,SecurityEventsLogging,SecurityEventsImmutable,SecurityEventsIndex,UnlockUser}Test.php

frontend/
├── packages/app/features/usuarios/api.ts              # securityEvents list, unlockUser; UserRecord.locked_until
└── apps/next/
    ├── features/usuarios/SecurityLogTab.tsx (+test)   # filtros tipo / usuario / fechas, paginado
    ├── features/usuarios/UsersTab.tsx                 # insignia "Bloqueada hasta HH:MM", acción Desbloquear
    ├── features/usuarios/UsuariosScreen.tsx           # pestaña Bitácora solo con usuarios.gestionar
    └── app/login/…                                    # aviso fijo de bloqueo (FR-002)
```

## Orden de implementación

1. Migraciones + trigger + modelo inmutable (tests de inmutabilidad, incluido UPDATE/DELETE por SQL directo).
2. Bloqueo en login (tests de RN-034: 5 fallos, correcta rechazada, vencimiento, reinicio, concurrencia, inactiva, inexistente, token móvil).
3. Registro de eventos en cada controlador (un test por evento de FR-005, incluido "no se registra si falla").
4. Consulta y desbloqueo (403 para no-admin).
5. Frontend: pestaña Bitácora, insignia/acción de desbloqueo, aviso en login.
6. `especialista-seguridad`, `qa-test-runner`, verificación en navegador, `CLAUDE.md` (pendiente nº 3 resuelto).
