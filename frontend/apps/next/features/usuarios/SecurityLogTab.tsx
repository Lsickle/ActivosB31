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
  // RN-015: vínculo usuario-empleado ({id, name}; en eventos antiguos, texto).
  employee: 'Empleado vinculado',
  // settings_updated (Ajustes).
  expiry_alert_days: 'Anticipación de alertas de vencimiento (días)',
}

const LOGIN_FAILED_REASONS: Record<string, string> = {
  invalid: 'credenciales inválidas',
  locked: 'cuenta bloqueada',
  inactive: 'cuenta inactiva',
}

const SIGNATURE_FAILURE_REASONS: Record<string, string> = {
  altered: 'firma alterada',
  missing: 'archivo faltante',
}

const CHANNELS: Record<string, string> = {
  web: 'web',
  token: 'token (aplicación móvil)',
  cli: 'consola',
}

// report_exported (008, R-3): claves que registra ReportResponder en el backend.
const REPORT_NAMES: Record<string, string> = {
  inventory: 'Inventario general',
  employee: 'Por empleado',
  asset: 'Por activo',
  asset_type: 'Por tipo de artículo',
}

const REPORT_FORMATS: Record<string, string> = {
  xlsx: 'Excel',
  pdf: 'PDF',
}

const REPORT_FILTER_LABELS: Record<string, string> = {
  asset_category_id: 'Categoría',
  asset_type_id: 'Tipo',
  location_id: 'Ubicación',
  status: 'Estado',
  from: 'Desde',
  to: 'Hasta',
}

export type SecurityEventDetailLine = {
  text: string
  // Se muestra resaltada (p. ej. exportación con datos personales, Ley 1581).
  highlight?: boolean
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

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
}

function isPresent(value: unknown): boolean {
  return value !== null && value !== undefined && value !== ''
}

function describeChanges(value: unknown): string[] {
  if (!isRecord(value)) return []
  return Object.entries(value).map(([field, change]) => {
    const label = FIELD_LABELS[field] ?? field
    if (Array.isArray(change) && change.length === 2) {
      return `${label}: ${formatValue(change[0])} → ${formatValue(change[1])}`
    }
    return `${label}: ${formatValue(change)}`
  })
}

function describeUnknown(metadata: Record<string, unknown>): SecurityEventDetailLine[] {
  return Object.entries(metadata).map(([key, value]) => ({ text: `${key}: ${formatValue(value)}` }))
}

function describeReportExported(metadata: Record<string, unknown>): SecurityEventDetailLine[] {
  const { report, subject_id: subjectId, format, filters, personal_data: personalData, ...rest } = metadata
  const lines: SecurityEventDetailLine[] = []
  if (isPresent(report)) lines.push({ text: `Reporte: ${REPORT_NAMES[String(report)] ?? formatValue(report)}` })
  if (isPresent(format)) lines.push({ text: `Formato: ${REPORT_FORMATS[String(format)] ?? formatValue(format)}` })
  if (isPresent(subjectId)) lines.push({ text: `Sujeto: #${String(subjectId)}` })
  // Sin filtros, PHP serializa el arreglo vacío como [] en vez de {}.
  if (isRecord(filters)) {
    const parts = Object.entries(filters)
      .filter(([, value]) => isPresent(value))
      .map(([key, value]) => `${REPORT_FILTER_LABELS[key] ?? key}=${formatValue(value)}`)
    if (parts.length > 0) lines.push({ text: `Filtros: ${parts.join(', ')}` })
  }
  if (personalData === true) lines.push({ text: 'Incluye datos personales', highlight: true })
  return [...lines, ...describeUnknown(rest)]
}

function describeSignatureIntegrityFailed(metadata: Record<string, unknown>): SecurityEventDetailLine[] {
  const { delivery_id: deliveryId, code, reason, ...rest } = metadata
  const lines: SecurityEventDetailLine[] = []
  if (typeof code === 'string' && code !== '') {
    lines.push({ text: `Entrega: ${code}` })
  } else if (isPresent(deliveryId)) {
    lines.push({ text: `Entrega: #${String(deliveryId)}` })
  }
  if (isPresent(reason)) {
    lines.push({ text: `Motivo: ${SIGNATURE_FAILURE_REASONS[String(reason)] ?? formatValue(reason)}` })
  }
  return [...lines, ...describeUnknown(rest)]
}

// Convierte `metadata` (contracts/api.md, tabla de eventos) en líneas legibles.
// Las claves desconocidas se muestran tal cual para no ocultar información.
// report_exported y signature_integrity_failed tienen formato propio porque
// sus claves (p. ej. `reason`) no significan lo mismo que en el resto.
export function describeSecurityEvent(event: SecurityEvent): SecurityEventDetailLine[] {
  const lines: SecurityEventDetailLine[] = []
  if (event.login_attempted) {
    lines.push({ text: `Usuario ingresado: ${event.login_attempted}` })
  }
  const metadata = event.metadata ?? {}
  if (event.event === 'report_exported') return [...lines, ...describeReportExported(metadata)]
  if (event.event === 'signature_integrity_failed') return [...lines, ...describeSignatureIntegrityFailed(metadata)]
  return [...lines, ...describeGeneric(metadata).map((text) => ({ text }))]
}

function describeGeneric(metadata: Record<string, unknown>): string[] {
  const lines: string[] = []
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
        lines.push(...describeChanges(value))
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
                  {line.highlight ? <strong className="font-semibold text-destructive">{line.text}</strong> : line.text}
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
