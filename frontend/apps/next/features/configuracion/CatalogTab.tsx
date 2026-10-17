'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { BanIcon, ChevronLeftIcon, ChevronRightIcon, CircleCheckIcon, PencilIcon, PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import type { Paginated, PaginationMeta } from 'app/lib/api-helpers'
import { useCan } from 'app/provider/auth'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'

const PER_PAGE = 25 // contracts/api.md: por defecto 25
const SEARCH_DEBOUNCE_MS = 300

export const ALL = 'all'

const STATUS_ITEMS = [
  { value: ALL, label: 'Todos los estados' },
  { value: 'active', label: 'Activos' },
  { value: 'inactive', label: 'Inactivos' },
]

export type CatalogItem = { id: number; name: string; description: string | null; is_active: boolean }

export type CatalogFilter = {
  key: string
  /** Etiqueta accesible del select (p. ej. "Filtrar por categoría"). */
  label: string
  items: { value: string; label: string }[]
}

export type CatalogFetchParams = {
  search: string
  isActive: boolean | undefined
  page: number
  perPage: number
  /** Valores de los filtros propios del catálogo; se omiten los que están en "todos". */
  filters: Record<string, string>
}

export type CatalogLabels = {
  /** En minúscula, p. ej. "categoría", "tipo de activo". */
  singular: string
  plural: string
  /** Género gramatical: "Categoría creada" / "Tipo creado". */
  feminine: boolean
  newLabel: string
  searchLabel: string
  emptyMessage: string
  deactivateDescription: string
  activateDescription: string
}

export type CatalogFormRenderProps<T> = {
  open: boolean
  onOpenChange: (open: boolean) => void
  record: T | null
  onSaved: (record: T, created: boolean) => void
}

function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1)
}

// Listado común de los catálogos de Configuración (FR-001..FR-004, FR-013):
// tabla paginada por el servidor (orden por nombre), búsqueda, filtro de
// estado y filtros propios. Crear, editar y activar/desactivar solo con
// configuracion.gestionar (FR-011); no existe eliminar (RN-003).
export function CatalogTab<T extends CatalogItem>({
  labels,
  fetchPage,
  filters = [],
  columns,
  updateStatus,
  renderForm,
}: {
  labels: CatalogLabels
  /** Debe ser estable (función de módulo o `useCallback`): cada cambio recarga. */
  fetchPage: (params: CatalogFetchParams) => Promise<Paginated<T>>
  filters?: CatalogFilter[]
  /** Columnas propias, entre Nombre y Estado. */
  columns: ColumnDef<T>[]
  updateStatus: (id: number, isActive: boolean) => Promise<unknown>
  renderForm: (props: CatalogFormRenderProps<T>) => React.ReactNode
}) {
  const canManage = useCan('configuracion.gestionar')
  const [records, setRecords] = useState<T[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState<string>(ALL)
  const [filterValues, setFilterValues] = useState<Record<string, string>>({})
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)

  const [formRecord, setFormRecord] = useState<T | null>(null)
  const [isFormOpen, setIsFormOpen] = useState(false)
  const [statusTarget, setStatusTarget] = useState<T | null>(null)

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])
  const entity = capitalize(labels.singular)
  const ending = labels.feminine ? 'a' : 'o'

  useEffect(() => {
    const timeout = setTimeout(() => {
      setSearch(searchInput)
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput])

  useEffect(() => {
    let cancelled = false
    fetchPage({
      search,
      isActive: statusFilter === ALL ? undefined : statusFilter === 'active',
      page,
      perPage: PER_PAGE,
      filters: Object.fromEntries(Object.entries(filterValues).filter(([, value]) => value !== ALL)),
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
  }, [fetchPage, search, statusFilter, filterValues, page, reloadKey])

  function openForm(record: T | null) {
    setFormRecord(record)
    setIsFormOpen(true)
  }

  function handleSaved(record: T, created: boolean) {
    setIsFormOpen(false)
    toast.success(`${entity} "${record.name}" ${created ? `cread${ending}` : `actualizad${ending}`}.`)
    reload()
  }

  async function handleStatusChange(): Promise<boolean> {
    if (!statusTarget) return true
    const activate = !statusTarget.is_active
    try {
      await updateStatus(statusTarget.id, activate)
      toast.success(`${entity} "${statusTarget.name}" ${activate ? `activad${ending}` : `desactivad${ending}`}.`)
      reload()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : `No fue posible cambiar el estado de ${labels.feminine ? 'la' : 'el'} ${labels.singular}.`)
    }
    return true
  }

  const tableColumns = useMemo<ColumnDef<T>[]>(() => {
    const base: ColumnDef<T>[] = [
      {
        accessorKey: 'name',
        header: 'Nombre',
        cell: ({ row }) => (
          <div className="flex flex-col items-start gap-0.5">
            <span className="font-medium">{row.original.name}</span>
            {row.original.description && (
              <span className="line-clamp-2 max-w-md text-xs whitespace-normal text-muted-foreground">{row.original.description}</span>
            )}
          </div>
        ),
      },
      ...columns,
      {
        accessorKey: 'is_active',
        header: 'Estado',
        cell: ({ row }) =>
          row.original.is_active ? (
            <Badge variant="outline">Activ{ending}</Badge>
          ) : (
            <Badge variant="destructive">Inactiv{ending}</Badge>
          ),
      },
    ]
    if (!canManage) return base
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
                aria-label={`Editar ${record.name}`}
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
                aria-label={`${statusLabel} ${record.name}`}
                title={statusLabel}
                onClick={() => setStatusTarget(record)}
              >
                {record.is_active ? <BanIcon /> : <CircleCheckIcon />}
              </Button>
            </div>
          )
        },
      },
    ]
  }, [canManage, columns, ending])

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <Input
          type="search"
          aria-label={labels.searchLabel}
          placeholder="Buscar por nombre"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="sm:max-w-64"
        />
        {filters.map((filter) => (
          <Select
            key={filter.key}
            items={filter.items}
            value={filterValues[filter.key] ?? ALL}
            onValueChange={(value) => {
              setFilterValues((current) => ({ ...current, [filter.key]: (value as string | null) ?? ALL }))
              setPage(1)
            }}
          >
            <SelectTrigger aria-label={filter.label} className="sm:w-44">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {filter.items.map((item) => (
                <SelectItem key={item.value} value={item.value}>
                  {item.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        ))}
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
            {labels.newLabel}
          </Button>
        )}
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar {labels.feminine ? 'las' : 'los'} {labels.plural}.
        </p>
      ) : records === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <DataTable columns={tableColumns} data={records} getRowId={(record) => String(record.id)} emptyMessage={labels.emptyMessage} />
      )}

      {meta && meta.total > 0 && (
        <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
          <span>
            Página {meta.current_page} de {meta.last_page} · {meta.total} {meta.total === 1 ? labels.singular : labels.plural}
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
          {renderForm({ open: isFormOpen, onOpenChange: setIsFormOpen, record: formRecord, onSaved: handleSaved })}
          <ConfirmDialog
            open={statusTarget !== null}
            onOpenChange={(open) => !open && setStatusTarget(null)}
            title={`¿${statusTarget?.is_active ? 'Desactivar' : 'Activar'} ${labels.feminine ? 'la' : 'el'} ${labels.singular} "${statusTarget?.name}"?`}
            description={statusTarget?.is_active ? labels.deactivateDescription : labels.activateDescription}
            confirmLabel={statusTarget?.is_active ? 'Desactivar' : 'Activar'}
            destructive={Boolean(statusTarget?.is_active)}
            onConfirm={handleStatusChange}
          />
        </>
      )}
    </div>
  )
}
