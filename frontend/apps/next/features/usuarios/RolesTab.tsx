'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import { listRoles, updateRoleStatus, type RoleRecord } from 'app/features/usuarios/api'
import { useCan } from 'app/provider/auth'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { RoleFormDialog } from './RoleFormDialog'

// US4 / FR-013: roles de la aplicación. Los precargados (is_system) no se
// desactivan; el servidor además rechaza (409) desactivar un rol con usuarios activos.
export function RolesTab() {
  const canManage = useCan('usuarios.gestionar')
  const [roles, setRoles] = useState<RoleRecord[] | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [formRole, setFormRole] = useState<RoleRecord | null>(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [statusTarget, setStatusTarget] = useState<RoleRecord | null>(null)

  const [reloadKey, setReloadKey] = useState(0)
  const load = useCallback(() => setReloadKey((key) => key + 1), [])

  useEffect(() => {
    let cancelled = false
    listRoles()
      .then(({ data }) => {
        if (cancelled) return
        setRoles(data)
        setLoadError(false)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [reloadKey])

  function openForm(role: RoleRecord | null) {
    setFormRole(role)
    setIsFormOpen(true)
  }

  function handleSaved(role: RoleRecord, created: boolean) {
    setIsFormOpen(false)
    toast.success(created ? `Rol "${role.name}" creado. Asígnale permisos en la pestaña Permisos.` : `Rol "${role.name}" actualizado.`)
    load()
  }

  async function handleStatusChange(): Promise<boolean> {
    if (!statusTarget) return true
    const activate = !statusTarget.is_active
    try {
      await updateRoleStatus(statusTarget.id, activate)
      toast.success(`Rol "${statusTarget.name}" ${activate ? 'activado' : 'desactivado'}.`)
      load()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No fue posible cambiar el estado del rol.')
    }
    return true
  }

  const columns = useMemo<ColumnDef<RoleRecord>[]>(() => {
    const base: ColumnDef<RoleRecord>[] = [
      {
        accessorKey: 'name',
        header: 'Nombre',
        cell: ({ row }) => (
          <span className="flex items-center gap-2 font-medium">
            {row.original.name}
            {row.original.is_system && <Badge variant="secondary">Del sistema</Badge>}
          </span>
        ),
      },
      {
        accessorKey: 'description',
        header: 'Descripción',
        cell: ({ row }) => <span className="text-muted-foreground">{row.original.description ?? '—'}</span>,
      },
      // RN-016: users_count cuenta solo usuarios activos.
      { accessorKey: 'users_count', header: 'Usuarios activos' },
      {
        accessorKey: 'is_active',
        header: 'Estado',
        cell: ({ row }) =>
          row.original.is_active ? <Badge variant="outline">Activo</Badge> : <Badge variant="destructive">Inactivo</Badge>,
      },
    ]
    if (!canManage) return base
    return [
      ...base,
      {
        id: 'actions',
        header: () => <span className="sr-only">Acciones</span>,
        cell: ({ row }) => {
          const role = row.original
          return (
            <div className="flex justify-end gap-1">
              <Button size="sm" variant="ghost" aria-label={`Editar ${role.name}`} onClick={() => openForm(role)}>
                Editar
              </Button>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`${role.is_active ? 'Desactivar' : 'Activar'} ${role.name}`}
                title={role.is_system ? 'Los roles del sistema no se pueden desactivar.' : undefined}
                disabled={role.is_system && role.is_active}
                onClick={() => setStatusTarget(role)}
              >
                {role.is_active ? 'Desactivar' : 'Activar'}
              </Button>
            </div>
          )
        },
      },
    ]
  }, [canManage])

  if (loadError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        No fue posible cargar los roles.
      </p>
    )
  }

  if (!roles) {
    return <Skeleton className="h-48 w-full" />
  }

  return (
    <div className="flex flex-col gap-3">
      {canManage && (
        <div className="flex justify-end">
          <Button onClick={() => openForm(null)}>
            <PlusIcon data-icon="inline-start" />
            Nuevo rol
          </Button>
        </div>
      )}
      <DataTable columns={columns} data={roles} getRowId={(role) => String(role.id)} emptyMessage="No hay roles." />
      {canManage && (
        <>
          <RoleFormDialog open={isFormOpen} onOpenChange={setIsFormOpen} role={formRole} onSaved={handleSaved} />
          <ConfirmDialog
            open={statusTarget !== null}
            onOpenChange={(open) => !open && setStatusTarget(null)}
            title={statusTarget?.is_active ? `¿Desactivar el rol "${statusTarget?.name}"?` : `¿Activar el rol "${statusTarget?.name}"?`}
            description={
              statusTarget?.is_active
                ? 'Mientras esté inactivo no se podrá asignar a usuarios.'
                : 'El rol volverá a estar disponible para asignarlo a usuarios.'
            }
            confirmLabel={statusTarget?.is_active ? 'Desactivar' : 'Activar'}
            destructive={Boolean(statusTarget?.is_active)}
            onConfirm={handleStatusChange}
          />
        </>
      )}
    </div>
  )
}
