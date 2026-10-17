'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { BanIcon, ChevronLeftIcon, ChevronRightIcon, CircleCheckIcon, PackageIcon, PencilIcon, PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import { employeesApi, type EmployeeRecord } from 'app/features/empleados/api'
import { employeeHoldings } from 'app/features/entregas/api'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { useCan, useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { useConfigurationOptions } from '@/features/configuracion/useConfigurationOptions'
import { EmployeeHoldingsDialog } from '@/features/entregas/EmployeeHoldingsDialog'
import { EmployeeFormDialog } from './EmployeeFormDialog'

const PER_PAGE = 25
const SEARCH_DEBOUNCE_MS = 300
const ALL = 'all'

const STATUS_ITEMS = [
  { value: ALL, label: 'Todos los estados' },
  { value: 'active', label: 'Activos' },
  { value: 'inactive', label: 'Inactivos' },
]

type FilterItem = { value: string; label: string }

function FilterSelect({
  label,
  items,
  value,
  onChange,
}: {
  label: string
  items: FilterItem[]
  value: string
  onChange: (value: string) => void
}) {
  return (
    <Select items={items} value={value} onValueChange={(next) => onChange((next as string | null) ?? ALL)}>
      <SelectTrigger aria-label={label} className="sm:w-44">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {items.map((item) => (
          <SelectItem key={item.value} value={item.value}>
            {item.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

function idFilter(value: string): number | undefined {
  return value === ALL ? undefined : Number(value)
}

// Módulo Empleados (spec 004): ver = empleados.ver (RN-022, Ley 1581); crear,
// editar y activar/desactivar = empleados.gestionar. No se eliminan (US1-5).
export function EmpleadosScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('empleados.ver')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">Empleados</h1>
        <p className="text-sm text-muted-foreground">Personal de la estación al que se le asignan activos.</p>
      </div>
      <EmployeesList />
    </div>
  )
}

// FR-001: listado paginado por el servidor (orden por apellidos y nombres),
// búsqueda por nombre, apellido o documento y filtros de cargo, compañía y estado.
function EmployeesList() {
  const canManage = useCan('empleados.gestionar')
  // Cargos y compañías activos (FR-004) para los filtros y el formulario.
  const { options, failed: optionsFailed } = useConfigurationOptions()

  const [records, setRecords] = useState<EmployeeRecord[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [positionFilter, setPositionFilter] = useState(ALL)
  const [companyFilter, setCompanyFilter] = useState(ALL)
  const [statusFilter, setStatusFilter] = useState(ALL)
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)

  const [formRecord, setFormRecord] = useState<EmployeeRecord | null>(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [statusTarget, setStatusTarget] = useState<EmployeeRecord | null>(null)
  // 007 D-007: tenencias (ver) y aviso de pendientes al desactivar (edge case).
  const [holdingsTarget, setHoldingsTarget] = useState<EmployeeRecord | null>(null)
  const [pendingOnDeactivate, setPendingOnDeactivate] = useState<{ employeeId: number; units: number } | null>(null)

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  // 007 edge case: se permite desactivar con artículos pendientes, pero se advierte.
  useEffect(() => {
    if (!statusTarget?.is_active) return
    let cancelled = false
    const employeeId = statusTarget.id
    employeeHoldings(employeeId)
      .then(({ data }) => {
        if (!cancelled) setPendingOnDeactivate({ employeeId, units: data.reduce((sum, row) => sum + row.pending_quantity, 0) })
      })
      .catch(() => {
        // El aviso es informativo: si falla, la confirmación sigue disponible.
      })
    return () => {
      cancelled = true
    }
  }, [statusTarget])

  const pendingUnits = pendingOnDeactivate && pendingOnDeactivate.employeeId === statusTarget?.id ? pendingOnDeactivate.units : 0

  useEffect(() => {
    const timeout = setTimeout(() => {
      setSearch(searchInput)
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput])

  useEffect(() => {
    let cancelled = false
    employeesApi
      .list({
        search,
        positionId: idFilter(positionFilter),
        companyId: idFilter(companyFilter),
        isActive: statusFilter === ALL ? undefined : statusFilter === 'active',
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
  }, [search, positionFilter, companyFilter, statusFilter, page, reloadKey])

  // Los filtros ofrecen los cargos y compañías activos; los empleados con uno
  // inactivo se ven con "Todos".
  const positionItems = useMemo<FilterItem[]>(
    () => [{ value: ALL, label: 'Todos los cargos' }, ...(options?.positions ?? []).map((p) => ({ value: String(p.id), label: p.name }))],
    [options]
  )
  const companyItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todas las compañías' },
      ...(options?.companies ?? []).map((c) => ({ value: String(c.id), label: c.name })),
    ],
    [options]
  )

  function changeFilter(setter: (value: string) => void) {
    return (value: string) => {
      setter(value)
      setPage(1)
    }
  }

  function openForm(record: EmployeeRecord | null) {
    setFormRecord(record)
    setIsFormOpen(true)
  }

  function handleSaved(record: EmployeeRecord, created: boolean) {
    setIsFormOpen(false)
    toast.success(`Empleado "${record.full_name}" ${created ? 'creado' : 'actualizado'}.`)
    reload()
  }

  async function handleStatusChange(): Promise<boolean> {
    if (!statusTarget) return true
    const activate = !statusTarget.is_active
    try {
      await employeesApi.updateStatus(statusTarget.id, activate)
      toast.success(`Empleado "${statusTarget.full_name}" ${activate ? 'activado' : 'desactivado'}.`)
      reload()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No fue posible cambiar el estado del empleado.')
    }
    return true
  }

  const columns = useMemo<ColumnDef<EmployeeRecord>[]>(() => {
    const base: ColumnDef<EmployeeRecord>[] = [
      {
        id: 'full_name',
        header: 'Nombre completo',
        cell: ({ row }) => <span className="font-medium">{row.original.full_name}</span>,
      },
      {
        id: 'document',
        header: 'Documento',
        cell: ({ row }) => (
          <span className="tabular-nums">
            {row.original.document_type} {row.original.document_number}
          </span>
        ),
      },
      {
        id: 'position',
        header: 'Cargo',
        cell: ({ row }) => (
          <div className="flex flex-wrap items-center gap-1.5">
            <span>{row.original.position.name}</span>
            {/* US2-1: el empleado conserva su cargo aunque se haya desactivado. */}
            {!row.original.position.is_active && <Badge variant="secondary">Cargo inactivo</Badge>}
          </div>
        ),
      },
      {
        id: 'company',
        header: 'Compañía',
        cell: ({ row }) =>
          row.original.company ? (
            <div className="flex flex-wrap items-center gap-1.5">
              <span>{row.original.company.name}</span>
              {!row.original.company.is_active && <Badge variant="secondary">Compañía inactiva</Badge>}
            </div>
          ) : (
            <span className="text-muted-foreground">—</span>
          ),
      },
      {
        id: 'phone',
        header: 'Teléfono',
        cell: ({ row }) => row.original.phone ?? <span className="text-muted-foreground">—</span>,
      },
      {
        id: 'is_active',
        header: 'Estado',
        cell: ({ row }) =>
          row.original.is_active ? <Badge variant="outline">Activo</Badge> : <Badge variant="destructive">Inactivo</Badge>,
      },
    ]
    return [
      ...base,
      {
        id: 'actions',
        header: () => <span className="sr-only">Acciones</span>,
        cell: ({ row }) => {
          const record = row.original
          const statusLabel = record.is_active ? 'Desactivar' : 'Activar'
          return (
            <div className="flex justify-end gap-1">
              <Button
                size="icon-sm"
                variant="ghost"
                aria-label={`Ver tenencias de ${record.full_name}`}
                title="Tiene asignado o prestado"
                onClick={() => setHoldingsTarget(record)}
              >
                <PackageIcon />
              </Button>
              {canManage && (
                <>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label={`Editar ${record.full_name}`}
                    title="Editar"
                    onClick={() => {
                      setFormRecord(record)
                      setIsFormOpen(true)
                    }}
                  >
                    <PencilIcon />
                  </Button>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    aria-label={`${statusLabel} ${record.full_name}`}
                    title={statusLabel}
                    onClick={() => setStatusTarget(record)}
                  >
                    {record.is_active ? <BanIcon /> : <CircleCheckIcon />}
                  </Button>
                </>
              )}
            </div>
          )
        },
      },
    ]
  }, [canManage])

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <Input
          type="search"
          aria-label="Buscar empleados"
          placeholder="Buscar por nombre o documento"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="sm:max-w-64"
        />
        <FilterSelect label="Filtrar por cargo" items={positionItems} value={positionFilter} onChange={changeFilter(setPositionFilter)} />
        <FilterSelect label="Filtrar por compañía" items={companyItems} value={companyFilter} onChange={changeFilter(setCompanyFilter)} />
        <FilterSelect label="Filtrar por estado" items={STATUS_ITEMS} value={statusFilter} onChange={changeFilter(setStatusFilter)} />
        {canManage && (
          <Button className="sm:ml-auto" onClick={() => openForm(null)}>
            <PlusIcon data-icon="inline-start" />
            Nuevo empleado
          </Button>
        )}
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar los empleados.
        </p>
      ) : records === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <DataTable
          columns={columns}
          data={records}
          getRowId={(record) => String(record.id)}
          emptyMessage="No hay empleados que coincidan con los filtros."
        />
      )}

      {meta && meta.total > 0 && (
        <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
          <span>
            Página {meta.current_page} de {meta.last_page} · {meta.total} {meta.total === 1 ? 'empleado' : 'empleados'}
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

      <EmployeeHoldingsDialog employee={holdingsTarget} onOpenChange={(open) => !open && setHoldingsTarget(null)} />

      {canManage && (
        <>
          <EmployeeFormDialog
            open={isFormOpen}
            onOpenChange={setIsFormOpen}
            employee={formRecord}
            options={options}
            optionsFailed={optionsFailed}
            onSaved={handleSaved}
          />
          <ConfirmDialog
            open={statusTarget !== null}
            onOpenChange={(open) => !open && setStatusTarget(null)}
            title={`¿${statusTarget?.is_active ? 'Desactivar' : 'Activar'} a "${statusTarget?.full_name}"?`}
            description={
              statusTarget?.is_active ? (
                <>
                  Se conserva con su historial y sigue visible con el filtro de estado, pero no se ofrecerá para nuevas entregas.
                  {pendingUnits > 0 && (
                    <span className="mt-2 block font-medium text-amber-700 dark:text-amber-500">
                      Tiene {pendingUnits} {pendingUnits === 1 ? 'artículo pendiente' : 'artículos pendientes'} de devolver; las devoluciones
                      seguirán siendo posibles.
                    </span>
                  )}
                </>
              ) : (
                'Volverá a estar disponible para nuevas entregas.'
              )
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
