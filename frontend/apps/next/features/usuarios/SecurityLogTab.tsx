'use client'

import { useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react'
import {
  listSecurityEvents,
  listSecurityEventTypes,
  listUsers,
  type PaginationMeta,
  type SecurityEvent,
  type SecurityEventType,
  type SecurityEventUser,
  type UserRecord,
} from 'app/features/usuarios/api'
import { DataTable } from '@/components/data-table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'

const PER_PAGE = 25
// Límite del backend para per_page (ListUsersRequest). Alcanza para la estación.
const USERS_FILTER_LIMIT = 100

const ALL = 'all'

const dateTimeFormat = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'medium' })

function formatDateTime(value: string): string {
  return dateTimeFormat.format(new Date(value))
}

function formatUser(user: SecurityEventUser): string {
  return `${user.name} (${user.username})`
}

const FIELD_LABELS: Record<string, string> = {
  name: 'Nombre',
  username: 'Usuario',
  email: 'Correo',
  role: 'Rol',
  role_id: 'Rol',
  description: 'Descripción',
  is_active: 'Estado',
  must_change_password: 'Cambio de contraseña pendiente',
}

const LOGIN_FAILED_REASONS: Record<string, string> = {
  invalid: 'credenciales inválidas',
  locked: 'cuenta bloqueada',
  inactive: 'cuenta inactiva',
}

const CHANNELS: Record<string, string> = {
  web: 'web',
  token: 'token (aplicación móvil)',
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (Array.isArray(value)) return value.length === 0 ? '—' : value.map(formatValue).join(', ')
  if (typeof value === 'object') {
    const named = value as { name?: unknown }
    return typeof named.name === 'string' ? named.name : JSON.stringify(value)
  }
  return String(value)
}

// Convierte `metadata` (contracts/api.md, tabla de eventos) en líneas legibles.
// Las claves desconocidas se muestran tal cual para no ocultar información.
export function describeSecurityEvent(event: SecurityEvent): string[] {
  const lines: string[] = []
  if (event.login_attempted) {
    lines.push(`Usuario ingresado: ${event.login_attempted}`)
  }
  const metadata = event.metadata ?? {}
  for (const [key, value] of Object.entries(metadata)) {
    switch (key) {
      case 'reason':
        lines.push(`Motivo: ${LOGIN_FAILED_REASONS[String(value)] ?? formatValue(value)}`)
        break
      case 'channel':
        lines.push(`Canal: ${CHANNELS[String(value)] ?? formatValue(value)}`)
        break
      case 'until':
        lines.push(`Bloqueada hasta: ${typeof value === 'string' ? formatDateTime(value) : formatValue(value)}`)
        break
      case 'role':
        lines.push(`Rol: ${formatValue(value)}`)
        break
      case 'is_active':
        lines.push(value ? 'Activado' : 'Desactivado')
        break
      case 'added':
        if (Array.isArray(value) && value.length > 0) lines.push(`Agregados: ${formatValue(value)}`)
        break
      case 'removed':
        if (Array.isArray(value) && value.length > 0) lines.push(`Quitados: ${formatValue(value)}`)
        break
      case 'changes':
        if (value && typeof value === 'object') {
          for (const [field, change] of Object.entries(value as Record<string, unknown>)) {
            const label = FIELD_LABELS[field] ?? field
            if (Array.isArray(change) && change.length === 2) {
              lines.push(`${label}: ${formatValue(change[0])} → ${formatValue(change[1])}`)
            } else {
              lines.push(`${label}: ${formatValue(change)}`)
            }
          }
        }
        break
      default:
        lines.push(`${FIELD_LABELS[key] ?? key}: ${formatValue(value)}`)
    }
  }
  return lines
}

// 003-seguridad-bitacora US3 / FR-008: consulta de la bitácora (solo
// usuarios.gestionar). El servidor ordena del más reciente al más antiguo y
// pagina; los filtros se aplican en el servidor. Muestra IP y navegador
// (datos personales, Ley 1581): solo la ve el Administrador.
export function SecurityLogTab() {
  const [types, setTypes] = useState<SecurityEventType[]>([])
  const [users, setUsers] = useState<UserRecord[]>([])
  const [events, setEvents] = useState<SecurityEvent[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)

  const [eventFilter, setEventFilter] = useState<string>(ALL)
  const [userFilter, setUserFilter] = useState<string>(ALL)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    listSecurityEventTypes()
      .then(({ data }) => setTypes(data))
      .catch(() => setTypes([]))
    listUsers({ perPage: USERS_FILTER_LIMIT })
      .then(({ data }) => setUsers(data))
      .catch(() => setUsers([]))
  }, [])

  useEffect(() => {
    let cancelled = false
    listSecurityEvents({
      event: eventFilter === ALL ? undefined : eventFilter,
      userId: userFilter === ALL ? undefined : Number(userFilter),
      from,
      to,
      page,
      perPage: PER_PAGE,
    })
      .then((response) => {
        if (cancelled) return
        setEvents(response.data)
        setMeta(response.meta)
        setLoadError(false)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [eventFilter, userFilter, from, to, page])

  const typeItems = useMemo(
    () => [{ value: ALL, label: 'Todos los eventos' }, ...types.map((type) => ({ value: type.code, label: type.label }))],
    [types]
  )
  const userItems = useMemo(
    () => [{ value: ALL, label: 'Todos los usuarios' }, ...users.map((user) => ({ value: String(user.id), label: formatUser(user) }))],
    [users]
  )

  const columns = useMemo<ColumnDef<SecurityEvent>[]>(
    () => [
      {
        accessorKey: 'created_at',
        header: 'Fecha y hora',
        cell: ({ row }) => <span className="whitespace-nowrap">{formatDateTime(row.original.created_at)}</span>,
      },
      { accessorKey: 'event_label', header: 'Evento' },
      {
        id: 'actor',
        header: 'Actor',
        cell: ({ row }) =>
          row.original.actor ? (
            <span title={row.original.actor.username}>{row.original.actor.name}</span>
          ) : (
            <span className="text-muted-foreground">Sistema</span>
          ),
      },
      {
        id: 'target',
        header: 'Afectado',
        cell: ({ row }) => {
          const { target_user: targetUser, target_role: targetRole } = row.original
          if (targetUser) return formatUser(targetUser)
          if (targetRole) return `Rol: ${targetRole.name}`
          return <span className="text-muted-foreground">—</span>
        },
      },
      {
        accessorKey: 'ip_address',
        header: 'IP',
        cell: ({ row }) => (
          <span className="font-mono text-xs" title={row.original.user_agent ?? undefined}>
            {row.original.ip_address ?? '—'}
          </span>
        ),
      },
      {
        id: 'detail',
        header: 'Detalle',
        cell: ({ row }) => {
          const lines = describeSecurityEvent(row.original)
          if (lines.length === 0) return <span className="text-muted-foreground">—</span>
          return (
            <ul className="flex flex-col gap-0.5 text-xs">
              {lines.map((line, index) => (
                <li key={index} className="break-words">
                  {line}
                </li>
              ))}
            </ul>
          )
        },
      },
    ],
    []
  )

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
        <Select
          items={typeItems}
          value={eventFilter}
          onValueChange={(value) => {
            setEventFilter((value as string | null) ?? ALL)
            setPage(1)
          }}
        >
          <SelectTrigger aria-label="Filtrar por tipo de evento" className="sm:w-60">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {typeItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          items={userItems}
          value={userFilter}
          onValueChange={(value) => {
            setUserFilter((value as string | null) ?? ALL)
            setPage(1)
          }}
        >
          <SelectTrigger aria-label="Filtrar por usuario" className="sm:w-60">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {userItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <div className="flex items-center gap-2">
          <Label htmlFor="security-log-from">Desde</Label>
          <Input
            id="security-log-from"
            type="date"
            value={from}
            max={to || undefined}
            onChange={(event) => {
              setFrom(event.target.value)
              setPage(1)
            }}
            className="w-40"
          />
        </div>
        <div className="flex items-center gap-2">
          <Label htmlFor="security-log-to">Hasta</Label>
          <Input
            id="security-log-to"
            type="date"
            value={to}
            min={from || undefined}
            onChange={(event) => {
              setTo(event.target.value)
              setPage(1)
            }}
            className="w-40"
          />
        </div>
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar la bitácora.
        </p>
      ) : events === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <DataTable
          columns={columns}
          data={events}
          getRowId={(event) => String(event.id)}
          emptyMessage="No hay eventos que coincidan con los filtros."
        />
      )}

      {meta && meta.total > 0 && (
        <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
          <span>
            Página {meta.current_page} de {meta.last_page} · {meta.total} {meta.total === 1 ? 'evento' : 'eventos'}
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
    </div>
  )
}
