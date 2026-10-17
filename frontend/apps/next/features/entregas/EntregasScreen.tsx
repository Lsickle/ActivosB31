'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import Link from 'next/link'
import type { ColumnDef } from '@tanstack/react-table'
import { BellIcon, PlusIcon } from 'lucide-react'
import { employeesOptions, type EmployeeOption } from 'app/features/empleados/api'
import { deliveriesApi, type DeliveryRecord, type OverdueDeliveryAlert } from 'app/features/entregas/api'
import {
  DELIVERY_KINDS,
  DELIVERY_KIND_LABELS,
  DELIVERY_STATUSES,
  DELIVERY_STATUS_LABELS,
  type DeliveryKind,
  type DeliveryStatus,
} from 'app/features/entregas/schemas'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { useCan, useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { DataTable } from '@/components/data-table'
import { buttonVariants } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { cn } from '@/lib/utils'
import { formatDate, formatDateTime } from '@/features/activos/format'
import { Pager } from '@/features/activos/Pager'
import { ALL, FilterSelect, idFilter, PER_PAGE, type FilterItem } from '@/features/inventario/shared'
import { DeliveryStatusBadge, formatQuantity, muted } from './shared'

const SEARCH_DEBOUNCE_MS = 300

const KIND_ITEMS: FilterItem[] = [
  { value: ALL, label: 'Todos los tipos' },
  ...DELIVERY_KINDS.map((kind) => ({ value: kind, label: DELIVERY_KIND_LABELS[kind] })),
]

const STATUS_ITEMS: FilterItem[] = [
  { value: ALL, label: 'Todos los estados' },
  ...DELIVERY_STATUSES.map((status) => ({ value: status, label: DELIVERY_STATUS_LABELS[status] })),
]

function pendingUnits(record: DeliveryRecord): number {
  return record.items.reduce((sum, item) => sum + item.pending_quantity, 0)
}

const COLUMNS: ColumnDef<DeliveryRecord>[] = [
  {
    id: 'code',
    header: 'Código',
    cell: ({ row }) => (
      <Link href={`/entregas/${row.original.id}`} className="font-medium tabular-nums underline-offset-4 hover:underline">
        {row.original.code}
      </Link>
    ),
  },
  {
    id: 'employee',
    header: 'Empleado',
    // RN-022 / D-006: solo nombre y cargo.
    cell: ({ row }) => (
      <div className="flex flex-col">
        <span>{row.original.employee.full_name}</span>
        <span className="text-xs text-muted-foreground">{row.original.employee.position}</span>
      </div>
    ),
  },
  { id: 'kind', header: 'Tipo', cell: ({ row }) => DELIVERY_KIND_LABELS[row.original.kind] ?? row.original.kind_label },
  { id: 'delivered_at', header: 'Entregada', cell: ({ row }) => formatDateTime(row.original.delivered_at) },
  {
    id: 'due_on',
    header: 'Devolución esperada',
    cell: ({ row }) => (row.original.due_on ? formatDate(row.original.due_on) : muted),
  },
  {
    id: 'items',
    header: 'Artículos',
    cell: ({ row }) => {
      const pending = pendingUnits(row.original)
      return (
        <div className="flex flex-col">
          <span>{row.original.items.length}</span>
          {pending > 0 && <span className="text-xs text-muted-foreground">{formatQuantity(pending)} pendientes</span>}
        </div>
      )
    },
  },
  { id: 'status', header: 'Estado', cell: ({ row }) => <DeliveryStatusBadge status={row.original.status} /> },
]

// Módulo Entregas (spec 007): ver = entregas.ver; registrar = entregas.gestionar (FR-006).
export function EntregasScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('entregas.ver')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">Entregas</h1>
        <p className="text-sm text-muted-foreground">Dotación y préstamos de activos a empleados, con firma y devoluciones.</p>
      </div>
      <OverdueAlertsCard />
      <DeliveriesList />
    </div>
  )
}

// FR-005 / RN-026: préstamos vencidos con pendientes. Solo aparece si hay alguno.
function OverdueAlertsCard() {
  const [alerts, setAlerts] = useState<OverdueDeliveryAlert[] | null>(null)

  useEffect(() => {
    let cancelled = false
    deliveriesApi
      .alerts()
      .then(({ data }) => {
        if (!cancelled) setAlerts(data)
      })
      .catch(() => {
        // Las alertas son un complemento: si fallan, el listado sigue funcionando.
        if (!cancelled) setAlerts([])
      })
    return () => {
      cancelled = true
    }
  }, [])

  if (!alerts || alerts.length === 0) return null

  return (
    <section aria-labelledby="overdue-alerts-title">
      <Card className="border-destructive/40">
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <BellIcon className="size-4 text-destructive" aria-hidden />
            <h2 id="overdue-alerts-title">Préstamos vencidos</h2>
          </CardTitle>
          <CardDescription>Préstamos con artículos pendientes cuya fecha de devolución ya pasó.</CardDescription>
        </CardHeader>
        <CardContent>
          <ul className="flex flex-col gap-2">
            {alerts.map((alert) => (
              <li key={alert.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2.5 text-sm">
                <div className="min-w-0">
                  <Link href={`/entregas/${alert.id}`} className="font-medium tabular-nums underline-offset-4 hover:underline">
                    {alert.code}
                  </Link>
                  <p className="text-xs text-muted-foreground">
                    {alert.employee.full_name} · {alert.employee.position} · {formatQuantity(alert.pending_quantity)}{' '}
                    {alert.pending_quantity === 1 ? 'pendiente' : 'pendientes'}
                  </p>
                </div>
                <span className="text-xs text-destructive">Vencía el {formatDate(alert.due_on)}</span>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>
    </section>
  )
}

// US3: listado paginado (más recientes primero) con búsqueda por código o
// empleado y filtros de empleado, tipo, estado y rango de fechas.
function DeliveriesList() {
  const canManage = useCan('entregas.gestionar')

  const [records, setRecords] = useState<DeliveryRecord[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [employees, setEmployees] = useState<EmployeeOption[]>([])

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [employeeFilter, setEmployeeFilter] = useState(ALL)
  const [kindFilter, setKindFilter] = useState(ALL)
  const [statusFilter, setStatusFilter] = useState(ALL)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)

  useEffect(() => {
    const timeout = setTimeout(() => {
      setSearch(searchInput)
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput])

  useEffect(() => {
    let cancelled = false
    employeesOptions()
      .then(({ data }) => {
        if (!cancelled) setEmployees(data)
      })
      .catch(() => {
        // Sin opciones el filtro queda solo con "Todos"; la búsqueda por nombre sigue disponible.
      })
    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    let cancelled = false
    deliveriesApi
      .list({
        search,
        employeeId: idFilter(employeeFilter),
        kind: kindFilter === ALL ? undefined : (kindFilter as DeliveryKind),
        status: statusFilter === ALL ? undefined : (statusFilter as DeliveryStatus),
        from: from || undefined,
        to: to || undefined,
        page,
        perPage: PER_PAGE,
      })
      .then((response) => {
        if (cancelled) return
        setRecords(response.data)
        setMeta(response.meta)
        setLoadError(false)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [search, employeeFilter, kindFilter, statusFilter, from, to, page])

  // Empleados activos (D-004 de 004); los inactivos se encuentran con la búsqueda.
  const employeeItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todos los empleados' },
      ...employees.map((employee) => ({ value: String(employee.id), label: `${employee.full_name} · ${employee.position}` })),
    ],
    [employees]
  )

  const changeFilter = useCallback(
    (setter: (value: string) => void) => (value: string) => {
      setter(value)
      setPage(1)
    },
    []
  )

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
        <Input
          type="search"
          aria-label="Buscar entregas"
          placeholder="Buscar por código o empleado"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="sm:max-w-64"
        />
        <FilterSelect label="Filtrar por empleado" items={employeeItems} value={employeeFilter} onChange={changeFilter(setEmployeeFilter)} />
        <FilterSelect label="Filtrar por tipo" items={KIND_ITEMS} value={kindFilter} onChange={changeFilter(setKindFilter)} />
        <FilterSelect label="Filtrar por estado" items={STATUS_ITEMS} value={statusFilter} onChange={changeFilter(setStatusFilter)} />
        <div className="flex items-end gap-2">
          <div className="flex flex-col gap-1">
            <Label htmlFor="deliveries-from" className="text-xs text-muted-foreground">
              Desde
            </Label>
            <Input id="deliveries-from" type="date" value={from} max={to || undefined} onChange={(event) => changeFilter(setFrom)(event.target.value)} className="w-40" />
          </div>
          <div className="flex flex-col gap-1">
            <Label htmlFor="deliveries-to" className="text-xs text-muted-foreground">
              Hasta
            </Label>
            <Input id="deliveries-to" type="date" value={to} min={from || undefined} onChange={(event) => changeFilter(setTo)(event.target.value)} className="w-40" />
          </div>
        </div>
        {canManage && (
          <Link href="/entregas/nueva" className={cn(buttonVariants(), 'sm:ml-auto')}>
            <PlusIcon data-icon="inline-start" />
            Nueva entrega
          </Link>
        )}
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar las entregas.
        </p>
      ) : records === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <div className="overflow-x-auto">
          <DataTable columns={COLUMNS} data={records} getRowId={(record) => String(record.id)} emptyMessage="No hay entregas que coincidan con los filtros." />
        </div>
      )}

      <Pager meta={meta} singular="entrega" plural="entregas" onPage={setPage} />
    </div>
  )
}
