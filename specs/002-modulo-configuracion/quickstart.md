# Quickstart: 002-modulo-configuracion

## Preparación

```bash
cd backend
docker compose exec -T laravel.test php artisan migrate --seed
docker compose exec -T laravel.test composer test --filter=Configuracion
cd ../frontend && yarn test && yarn typecheck && yarn dev   # http://localhost:3001
```

Usuarios: uno con rol Administrador y uno con rol Consulta (crearlos desde Usuarios si no existen).

## Escenarios

1. **Precarga (RN-012, FR-014)** — Tras `migrate --seed`: 9 categorías, 34 tipos de activo con su modo, 4 tipos de ubicación (Bodega, Vehículo, Oficina, Otro), 0 ubicaciones. Repetir `db:seed` no cambia los conteos. Renombrar una categoría y volver a sembrar no la recrea (D-008).
2. **Categorías (US1)** — Como Administrador en `/configuracion`: crear "Prueba", intentar "PRUEBA" → error de duplicado junto al campo; buscar "prueb" y "atencion" (sin tilde, encuentra "Atención prehospitalaria") → coincide; desactivar → sigue listada con filtro "Inactivas"; no hay opción de eliminar.
3. **Tipos de activo (US2)** — Crear "Guantes de rescate" en otra categoría → se acepta; en EPP → duplicado. Sin modo de control → no guarda. Desactivar la categoría "Prueba": no aparece en el select de nuevo tipo; sus tipos siguen listados con insignia "Categoría inactiva" y desaparecen de `GET /api/configuracion/options`.
4. **Ubicaciones (US3)** — Desactivar todos los tipos de ubicación: "Nueva ubicación" avisa que falta un tipo activo. Reactivar "Vehículo", crear "Máquina 1"; "máquina 1" → duplicado. Desactivar "Oficina": no aparece en el select; ubicaciones existentes de ese tipo lo conservan.
5. **Permisos (FR-011, RN-011)** — Como Consulta: ve las cuatro pestañas en solo lectura (sin Nuevo/Editar/Activar). Un `POST /api/asset-categories` directo responde 403. Quitar `configuracion.ver` al rol en la matriz → el menú oculta Configuración y la ruta muestra "Acceso denegado".
6. **Sin borrado (RN-003, SC-003)** — `DELETE /api/asset-categories/1` → 405.

Al terminar, revertir los cambios de prueba (reactivar, restaurar matriz) o recrear la BD local.
