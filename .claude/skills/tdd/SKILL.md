---
name: tdd
description: Ciclo TDD (red-green-refactor) para cambios de ActivosB31, en backend (Pest) y frontend web (Vitest + Testing Library). Úsalo en cualquier cambio Tier 1 o Tier 2 (ver constitución en .specify/memory/constitution.md) antes de escribir código de implementación — nunca escribas la implementación primero y el test después.
---

# TDD — red-green-refactor

No hay un mecanismo que fuerce este ciclo: es disciplina de proceso. Sigue los pasos en orden.

| | Backend (`backend/`) | Frontend web (`frontend/`) |
|---|---|---|
| Framework | Pest 4 | Vitest + Testing Library |
| Test filtrado | `docker compose exec laravel.test php artisan test --compact --filter=nombre` | `yarn workspace next-app vitest run -t "nombre"` |
| Suite completa | `docker compose exec laravel.test composer test` (en paralelo) | `yarn test` y `yarn typecheck` |
| Formato antes de cerrar | `docker compose exec laravel.test vendor/bin/pint --dirty` | (sin formateador automático todavía) |

En Windows con Git Bash, `vendor/bin/sail` no funciona; `docker compose exec laravel.test …` desde `backend/` es el equivalente.

## 1. Red — escribe el test que falla

- Expresa el comportamiento esperado; usa el ID de la regla (`RN-XXX`, `CU-XXX.Y`) en el nombre del test o un comentario cuando exista.
  - Backend: `tests/Feature/<Modulo>/` o `tests/Unit/`.
  - Frontend: junto al código bajo prueba en `apps/next`; la lógica de `packages/app` también se testea con Vitest (ver `vitest.config.ts`).
- Corre **solo ese test** y confirma que falla por la razón esperada, no por un error de setup.

## 2. Green — implementa lo mínimo

- La implementación más simple que pase el test; no adelantes funcionalidad que ningún test pide.

## 3. Refactor

- Limpia sin cambiar comportamiento; vuelve a correr el test tras cada cambio.

## 4. Suite completa

- Corre la suite relevante completa. Si algo más se rompió, arréglalo antes de continuar.

## 5. Cierre

- Si el cambio tiene superficie real (endpoint o pantalla), verifícalo de punta a punta: llamada real a la API, o `yarn dev` + navegador en `http://localhost:3001`.
- Backend: Pint antes de dar el cambio por terminado.

Para cambios Tier 0 (ver constitución) este ciclo no es obligatorio.
