# ActivosB31 Constitution

Este documento formaliza en formato spec-kit las reglas transversales de `CLAUDE.md` (fuente original: ante cualquier conflicto, `CLAUDE.md` manda). Aplica a todo el monorepo (`backend/`, `frontend/`).

## Core Principles

### I. Esquema como fuente de verdad (NON-NEGOTIABLE)
Antes de crear o modificar cualquier modelo, migración o repositorio se consulta el skill `esquema-bd`. No se inventan nombres de columna, tipos ni relaciones. Si el esquema no cubre algo, se propone el cambio, el usuario lo aprueba y `esquema-bd` se actualiza en el mismo cambio que la migración.

### II. Trazabilidad de negocio preservada
Los IDs de trazabilidad (RN-XXX, CU-XXX.Y, D-XXX) se preservan exactamente en comentarios, nombres de test, mensajes de commit y specs.

### III. No reinterpretación silenciosa de reglas de negocio
Si una regla es ambigua, incompleta o contradice otra, o si una pregunta abierta de `docs/modulos.md` no tiene respuesta del usuario, se señala — nunca se decide unilateralmente.

### IV. TDD obligatorio (NON-NEGOTIABLE)
Red-green-refactor en todo cambio Tier 1 y Tier 2. Pest en backend, Vitest + Testing Library en frontend.

### V. Revisión de seguridad antes de cerrar flujos sensibles
Antes de cerrar cualquier flujo de autenticación, autorización (RBAC), manejo de tokens o datos personales de empleados se invoca `especialista-seguridad`.

## Alcance técnico y de cumplimiento

Stack fijado en `CLAUDE.md`: Laravel 13/PHP 8.4, Sanctum, PostgreSQL 17, Next.js 16 + React 19 + Solito, Tailwind v4 + shadcn/ui (Base UI), despliegue en Render (backend) y Vercel (frontend). Una sola organización (Estación de Bomberos de Madrid, Cundinamarca). Los datos personales de empleados se rigen por la Ley 1581 de 2012; el historial de entregas e inventario debe ser íntegro y trazable.

## Development Workflow

- **Tier 0** (copy, color, dato de seeder): edición directa, sin spec ni TDD formal.
- **Tier 1** (bug fix o feature pequeña de un solo dominio): TDD, sin spec-kit completo.
- **Tier 2** (módulo nuevo, cambio de esquema/API/UI que cruza capas, o que toca auth/RBAC): `/speckit-specify` → `/speckit-plan` → `/speckit-tasks` → `/speckit-implement`, con TDD, revisión de seguridad si aplica y verificación de punta a punta antes de cerrar.

Los subagentes de dominio (`backend-laravel`, `frontend-web`) se invocan desde el hilo principal, nunca de forma autónoma.

## Governance

Cambiar un principio aquí requiere el mismo criterio que cambiar `CLAUDE.md`: señalarlo explícitamente al usuario. `/speckit-analyze` se usa para detectar inconsistencias antes de implementar.

**Version**: 1.0.0 | **Ratified**: 2026-10-17 | **Last Amended**: 2026-10-17
