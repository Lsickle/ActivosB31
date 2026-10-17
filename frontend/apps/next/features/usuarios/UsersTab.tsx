'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { ChevronLeftIcon, ChevronRightIcon, KeyRoundIcon, LockOpenIcon, PencilIcon, PlusIcon, UserCheckIcon, UserXIcon } from 'lucide-react'
import { toast } from 'sonner'
import {
  listRoles,
  listUsers,
  unlockUser,
  updateUserStatus,
  type PaginationMeta,
  type RoleRecord,
  type UserRecord,
} from 'app/features/usuarios/api'
import { useAuth, useCan } from 'app/provider/auth'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { ResetPasswordDialog } from './ResetPasswordDialog'
import { UserFormDialog } from './UserFormDialog'

const PER_PAGE = 20
const SEARCH_DEBOUNCE_MS = 300

const ALL = 'all'
const STATUS_ITEMS = [
  { value: ALL, label: 'Todos los estados' },
  { value: 'active', label: 'Activos' },
  { value: 'inactive', label: 'Inactivos' },
]

const dateTimeFormat = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short' })

function formatLastLogin(value: string | null): string {
  return value ? dateTimeFormat.format(new Date(value)) : 'Nunca'
}

// RN-034 (003): el bloqueo dura 15 min, así que basta la hora (24 h).
const lockTimeFormat = new Intl.DateTimeFormat('es-CO', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })

// El backend ya devuelve null si el bloqueo venció; se revisa de nuevo por si
// la lista lleva rato abierta.
function isLocked(user: UserRecord): boolean {
  return user.locked_until !== null && new Date(user.locked_until).getTime() > Date.now()
}

// US2 / FR-007: usuarios del sistema. Se crean, editan, desactivan y
// reactivan; no se borran. Las acciones solo aparecen con usuarios.gestionar.
export function UsersTab() {
  const canManage = useCan('usuarios.gestionar')
  const { user: currentUser } = useAuth()
  const [roles, setRoles] = useState<RoleRecord[]>([])
  const [users, setUsers] = useState<UserRecord[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [roleFilter, setRoleFilter] = useState<string>(ALL)
  const [statusFilter, setStatusFilter] = useState<string>(ALL)
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)

  const [formUser, setFormUser] = useState<UserRecord | null>(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [resetTarget, setResetTarget] = useState<UserRecord | null>(null)
  const [statusTarget, setStatusTarget] = useState<UserRecord | null>(null)
  const [unlockTarget, setUnlockTarget] = useState<UserRecord | null>(null)

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  useEffect(() => {
    listRoles()
      .then(({ data }) => setRoles(data))
      .catch(() => setRoles([]))
  }, [])

  useEffect(() => {
    const timeout = setTimeout(() => {
      setSearch(searchInput)
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput])

  useEffect(() => {
    let cancelled = false
    listUsers({
      search,
      roleId: roleFilter === ALL ? undefined : Number(roleFilter),
      isActive: statusFilter === ALL ? undefined : statusFilter === 'active',
      page,
      perPage: PER_PAGE,
    })
      .then((response) => {
        if (cancelled) return
        setUsers(response.data)
        setMeta(response.meta)
        setLoadError(false)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [search, roleFilter, statusFilter, page, reloadKey])

  const roleItems = useMemo(
    () => [{ value: ALL, label: 'Todos los roles' }, ...roles.map((role) => ({ value: String(role.id), label: role.name }))],
    [roles]
  )

  function openForm(user: UserRecord | null) {
    setFormUser(user)
    setIsFormOpen(true)
  }

  function handleSaved(user: UserRecord, created: boolean) {
    setIsFormOpen(false)
    toast.success(created ? `Usuario ${user.username} creado.` : `Usuario ${user.username} actualizado.`)
    reload()
  }

  function handleReset(user: UserRecord) {
    setResetTarget(null)
    toast.success(`Contraseña de ${user.username} restablecida. Deberá cambiarla al ingresar.`)
    reload()
  }

  async function handleStatusChange(): Promise<boolean> {
    if (!statusTarget) return true
    const activate = !statusTarget.is_active
    try {
      await updateUserStatus(statusTarget.id, activate)
      toast.success(`Usuario ${statusTarget.username} ${activate ? 'activado' : 'desactivado'}.`)
      reload()
    } catch (err) {
      // 409: último Administrador activo o la propia cuenta (FR-012).
      toast.error(err instanceof Error ? err.message : 'No fue posible cambiar el estado del usuario.')
    }
    return true
  }

  async function handleUnlock(): Promise<boolean> {
    if (!unlockTarget) return true
    try {
      await unlockUser(unlockTarget.id)
      toast.success(`Cuenta ${unlockTarget.username} desbloqueada.`)
      reload()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No fue posible desbloquear la cuenta.')
    }
    return true
  }

  const columns = useMemo<ColumnDef<UserRecord>[]>(() => {
    const base: ColumnDef<UserRecord>[] = [
      {
        accessorKey: 'name',
        header: 'Nombre',
        cell: ({ row }) => (
          <div className="flex flex-col items-start gap-1">
            <span className="font-medium">{row.original.name}</span>
            {row.original.employee && (
              <span className="text-xs text-muted-foreground">Empleado: {row.original.employee.full_name}</span>
            )}
            {row.original.must_change_password && <Badge variant="secondary">Cambio de contraseña pendiente</Badge>}
            {isLocked(row.original) && (
              <Badge variant="destructive">Bloqueada hasta {lockTimeFormat.format(new Date(row.original.locked_until!))}</Badge>
            )}
          </div>
        ),
      },
      { accessorKey: 'username', header: 'Usuario' },
      {
        accessorKey: 'email',
        header: 'Correo',
        cell: ({ row }) => row.original.email ?? <span className="text-muted-foreground">—</span>,
      },
      { id: 'role', header: 'Rol', cell: ({ row }) => row.original.role?.name ?? '—' },
      {
        accessorKey: 'is_active',
        header: 'Estado',
        cell: ({ row }) =>
          row.original.is_active ? <Badge variant="outline">Activo</Badge> : <Badge variant="destructive">Inactivo</Badge>,
      },
      {
        accessorKey: 'last_login_at',
        header: 'Último ingreso',
        cell: ({ row }) => <span className="text-muted-foreground">{formatLastLogin(row.original.last_login_at)}</span>,
      },
    ]
    if (!canManage) return base
    return [
      ...base,
      {
        id: 'actions',
        header: () => <span className="sr-only">Acciones</span>,
        cell: ({ row }) => {
          const user = row.original
          const isSelf = user.id === currentUser?.id
          const statusLabel = user.is_active ? 'Desactivar' : 'Activar'
          return (
            <div className="flex justify-end gap-1">
              <Button size="icon-sm" variant="ghost" aria-label={`Editar ${user.name}`} title="Editar" onClick={() => openForm(user)}>
                <PencilIcon />
              </Button>
              {/* RN-034 / FR-004: desbloqueo manual antes de que venzan los 15 min. */}
              {isLocked(user) && (
                <Button
                  size="icon-sm"
                  variant="ghost"
                  aria-label={`Desbloquear ${user.name}`}
                  title="Desbloquear"
                  onClick={() => setUnlockTarget(user)}
                >
                  <LockOpenIcon />
                </Button>
              )}
              {/* RN-018: la propia contraseña se cambia en "Cambiar contraseña" (pide la actual). */}
              {!isSelf && (
                <Button
                  size="icon-sm"
                  variant="ghost"
                  aria-label={`Restablecer contraseña de ${user.name}`}
                  title="Restablecer contraseña"
                  onClick={() => setResetTarget(user)}
                >
                  <KeyRoundIcon />
                </Button>
              )}
              <Button
                size="icon-sm"
                variant="ghost"
                aria-label={`${statusLabel} ${user.name}`}
                title={isSelf && user.is_active ? 'No puedes desactivar tu propia cuenta.' : statusLabel}
                disabled={isSelf && user.is_active}
                onClick={() => setStatusTarget(user)}
              >
                {user.is_active ? <UserXIcon /> : <UserCheckIcon />}
              </Button>
            </div>
          )
        },
      },
    ]
  }, [canManage, currentUser?.id])

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <Input
          type="search"
          aria-label="Buscar usuarios"
          placeholder="Buscar por nombre, usuario o correo"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="sm:max-w-xs"
        />
        <Select
          items={roleItems}
          value={roleFilter}
          onValueChange={(value) => {
            setRoleFilter((value as string | null) ?? ALL)
            setPage(1)
          }}
        >
          <SelectTrigger aria-label="Filtrar por rol" className="sm:w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {roleItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          items={STATUS_ITEMS}
          value={statusFilter}
          onValueChange={(value) => {
            setStatusFilter((value as string | null) ?? ALL)
            setPage(1)
          }}
        >
          <SelectTrigger aria-label="Filtrar por estado" className="sm:w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {STATUS_ITEMS.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {canManage && (
          <Button className="sm:ml-auto" onClick={() => openForm(null)}>
            <PlusIcon data-icon="inline-start" />
            Nuevo usuario
          </Button>
        )}
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar los usuarios.
        </p>
      ) : users === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <DataTable
          columns={columns}
          data={users}
          getRowId={(user) => String(user.id)}
          emptyMessage="No hay usuarios que coincidan con los filtros."
        />
      )}

      {meta && meta.total > 0 && (
        <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
          <span>
            Página {meta.current_page} de {meta.last_page} · {meta.total} {meta.total === 1 ? 'usuario' : 'usuarios'}
          </span>
          <Button
            size="icon-sm"
            variant="outline"
            aria-label="Página anterior"
            disabled={meta.current_page <= 1}
            onClick={() => setPage(meta.current_page - 1)}
          >
            <ChevronLeftIcon />
          </Button>
          <Button
            size="icon-sm"
            variant="outline"
            aria-label="Página siguiente"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => setPage(meta.current_page + 1)}
          >
            <ChevronRightIcon />
          </Button>
        </div>
      )}

      {canManage && (
        <>
          <UserFormDialog open={isFormOpen} onOpenChange={setIsFormOpen} user={formUser} roles={roles} onSaved={handleSaved} />
          <ResetPasswordDialog
            open={resetTarget !== null}
            onOpenChange={(open) => !open && setResetTarget(null)}
            user={resetTarget}
            onDone={handleReset}
          />
          <ConfirmDialog
            open={statusTarget !== null}
            onOpenChange={(open) => !open && setStatusTarget(null)}
            title={statusTarget?.is_active ? `¿Desactivar a ${statusTarget?.name}?` : `¿Activar a ${statusTarget?.name}?`}
            description={
              statusTarget?.is_active
                ? 'No podrá iniciar sesión y sus sesiones abiertas se cerrarán de inmediato.'
                : 'Podrá volver a iniciar sesión con su contraseña actual.'
            }
            confirmLabel={statusTarget?.is_active ? 'Desactivar' : 'Activar'}
            destructive={Boolean(statusTarget?.is_active)}
            onConfirm={handleStatusChange}
          />
          <ConfirmDialog
            open={unlockTarget !== null}
            onOpenChange={(open) => !open && setUnlockTarget(null)}
            title={`¿Desbloquear la cuenta de ${unlockTarget?.name}?`}
            description="Podrá volver a intentar el ingreso de inmediato y su contador de intentos fallidos vuelve a cero."
            confirmLabel="Desbloquear"
            onConfirm={handleUnlock}
          />
        </>
      )}
    </div>
  )
}
