# Quickstart / validación: 001-rbac

## Prerrequisitos

Stack local arriba (ver `CLAUDE.md` → Entorno local): API en http://localhost:8081, frontend en http://localhost:3001.

```bash
cd backend
docker compose exec laravel.test php artisan migrate --seed      # crea permisos, roles y asigna 'admin'
docker compose exec laravel.test composer test                   # Pest en paralelo
cd ../frontend && yarn test && yarn typecheck
```

## Escenarios de punta a punta (navegador)

1. **Admin con todos los permisos**: entrar como `admin` → el menú muestra los 7 módulos; `Usuarios y roles` tiene pestañas Usuarios, Roles y Permisos.
2. **Matriz** (US1): en Permisos, la columna Administrador está bloqueada. Marcar `Gestionar` en Empleados para Almacenista marca también `Ver`; guardar → toast de éxito.
3. **Crear usuario** (US2): crear `consulta1` con rol Consulta y contraseña temporal `Temporal123`. Crear `Consulta1` → error "ya existe". Crear `a@b` como usuario → error de formato.
4. **Cambio obligatorio** (US3): cerrar sesión, entrar como `consulta1` → solo se ve la pantalla de cambio de contraseña; intentar `Temporal123` de nuevo → rechazo; poner una válida → entra al tablero.
5. **UI según permisos** (US5): con `consulta1` el menú no muestra botones de crear/editar; navegar a `/usuarios` muestra solo lectura (tiene `usuarios.ver`). Quitar `usuarios.ver` a Consulta desde la matriz (como admin) → `consulta1` ve "Acceso denegado" en `/usuarios` al recargar.
6. **Revocación** (FR-011): con `consulta1` logueado en otra ventana, desactivarlo como admin → su siguiente acción responde 401 y vuelve al login.
7. **Último admin** (FR-012): intentar desactivar o cambiar de rol a `admin` siendo el único Administrador → mensaje de conflicto; desactivarse a sí mismo → rechazado.
8. **Roles** (US4): crear "Comandante" → aparece en la matriz sin permisos; intentar desactivar "Consulta" → rechazado (rol del sistema).

## Verificación del servidor (sin UI)

Los tests Pest cubren, por cada endpoint de escritura, la respuesta 403 de un usuario sin `gestionar` (SC-002). Ver `contracts/api.md`.
