'use client'

import { useEffect, useMemo, useState } from 'react'
import { LockIcon } from 'lucide-react'
import { toast } from 'sonner'
import {
  ADMIN_ROLE_CODE,
  listPermissions,
  listRoles,
  updateRolePermissions,
  type PermissionModule,
  type RoleRecord,
} from 'app/features/usuarios/api'
import { hasSamePermissions, togglePermission, type PermissionAction } from 'app/features/usuarios/permissions'
import { useCan } from 'app/provider/auth'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'

const ACTION_LABEL: Record<PermissionAction, string> = { ver: 'Ver', gestionar: 'Gestionar' }

// RN-019: gestionar Usuarios y roles es exclusivo del Administrador; el
// backend responde 422 si se intenta conceder a otro rol.
const ADMIN_ONLY_PERMISSIONS = ['usuarios.gestionar']
const ADMIN_ONLY_HINT = 'Exclusivo del Administrador'

// RN-009 / US1: matriz rol × módulo. Filas = módulos (GET /api/permissions),
// columnas = roles (GET /api/roles). Se guarda por rol (D-010) para no pisar
// los cambios que otro administrador haga en otros roles. La columna
// Administrador siempre tiene todo y no se edita (RN-010).
export function PermissionMatrix() {
  const canManage = useCan('usuarios.gestionar')
  const [modules, setModules] = useState<PermissionModule[] | null>(null)
  const [roles, setRoles] = useState<RoleRecord[]>([])
  const [draft, setDraft] = useState<Record<number, string[]>>({})
  const [savingRoleId, setSavingRoleId] = useState<number | null>(null)
  const [loadError, setLoadError] = useState(false)

  useEffect(() => {
    let cancelled = false
    Promise.all([listPermissions(), listRoles()])
      .then(([permissionsResponse, rolesResponse]) => {
        if (cancelled) return
        setModules(permissionsResponse.data)
        setRoles(rolesResponse.data)
        setDraft(Object.fromEntries(rolesResponse.data.map((role) => [role.id, role.permissions])))
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const allCodes = useMemo(() => (modules ?? []).flatMap((module) => module.permissions.map((permission) => permission.code)), [modules])

  if (loadError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        No fue posible cargar la matriz de permisos.
      </p>
    )
  }

  if (!modules) {
    return <Skeleton className="h-64 w-full" />
  }

  function isAdmin(role: RoleRecord) {
    return role.code === ADMIN_ROLE_CODE
  }

  function permissionsOf(role: RoleRecord): string[] {
    return isAdmin(role) ? allCodes : (draft[role.id] ?? [])
  }

  function handleToggle(role: RoleRecord, module: string, action: PermissionAction, checked: boolean) {
    setDraft((current) => ({ ...current, [role.id]: togglePermission(current[role.id] ?? [], module, action, checked) }))
  }

  async function handleSave(role: RoleRecord) {
    setSavingRoleId(role.id)
    try {
      const { data } = await updateRolePermissions(role.id, draft[role.id] ?? [])
      // El servidor normaliza (gestionar ⇒ ver): se adopta su versión.
      setRoles((current) => current.map((item) => (item.id === data.id ? data : item)))
      setDraft((current) => ({ ...current, [data.id]: data.permissions }))
      toast.success(`Permisos de ${role.name} guardados.`)
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No fue posible guardar los permisos.')
    } finally {
      setSavingRoleId(null)
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        {canManage
          ? 'Marca qué puede hacer cada rol en cada módulo. "Gestionar" incluye "Ver". Los cambios aplican a todos los usuarios del rol desde su siguiente acción.'
          : 'Vista de solo lectura: para modificar la matriz necesitas el permiso "Gestionar" de Usuarios y roles.'}{' '}
        "Gestionar" de Usuarios y roles es exclusivo del Administrador.
      </p>
      <div className="rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead scope="col" className="min-w-36">
                Módulo
              </TableHead>
              {roles.map((role) => (
                <TableHead key={role.id} scope="col" className="min-w-40 text-center">
                  <span className="inline-flex items-center gap-1.5">
                    {isAdmin(role) && <LockIcon className="size-3.5 text-muted-foreground" aria-hidden />}
                    {role.name}
                    {!role.is_active && <Badge variant="outline">Inactivo</Badge>}
                  </span>
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {modules.map((module) => (
              <TableRow key={module.module}>
                <TableHead scope="row" className="font-medium">
                  {module.label}
                </TableHead>
                {roles.map((role) => {
                  const granted = permissionsOf(role)
                  const readOnly = !canManage || isAdmin(role) || savingRoleId === role.id
                  return (
                    <TableCell key={role.id}>
                      <div className="flex items-center justify-center gap-4">
                        {module.permissions.map((permission) => {
                          const adminOnly = !isAdmin(role) && ADMIN_ONLY_PERMISSIONS.includes(permission.code)
                          return (
                            <label
                              key={permission.code}
                              className="flex items-center gap-1.5 text-xs"
                              title={adminOnly ? ADMIN_ONLY_HINT : undefined}
                            >
                              <Checkbox
                                aria-label={`${ACTION_LABEL[permission.action]} ${module.label} · ${role.name}`}
                                checked={granted.includes(permission.code)}
                                disabled={readOnly || adminOnly}
                                onCheckedChange={(checked) => handleToggle(role, module.module, permission.action, checked)}
                              />
                              {ACTION_LABEL[permission.action]}
                            </label>
                          )
                        })}
                      </div>
                    </TableCell>
                  )
                })}
              </TableRow>
            ))}
          </TableBody>
          {canManage && (
            <TableFooter>
              <TableRow>
                <TableCell />
                {roles.map((role) => (
                  <TableCell key={role.id} className="text-center">
                    {isAdmin(role) ? (
                      <span className="text-xs text-muted-foreground">Todos los permisos</span>
                    ) : (
                      <Button
                        size="sm"
                        aria-label={`Guardar ${role.name}`}
                        disabled={savingRoleId === role.id || hasSamePermissions(draft[role.id] ?? [], role.permissions)}
                        onClick={() => handleSave(role)}
                      >
                        {savingRoleId === role.id ? 'Guardando…' : 'Guardar'}
                      </Button>
                    )}
                  </TableCell>
                ))}
              </TableRow>
            </TableFooter>
          )}
        </Table>
      </div>
    </div>
  )
}
