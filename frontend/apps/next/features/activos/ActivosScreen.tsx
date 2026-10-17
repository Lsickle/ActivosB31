'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import Link from 'next/link'
import type { ColumnDef } from '@tanstack/react-table'
import { PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import { assetsApi, type AssetRecord } from 'app/features/activos/api'
import { ASSET_STATUSES, ASSET_STATUS_LABELS, type AssetStatus } from 'app/features/activos/schemas'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { useCan, useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { useConfigurationOptions } from '@/features/configuracion/useConfigurationOptions'
import { AssetAlertsCard } from './AssetAlertsCard'
import { AssetFormDialog } from './AssetFormDialog'
import { AssetStatusBadge } from './AssetStatusBadge'
import { formatDate } from './format'
import { Pager } from './Pager'

const PER_PAGE = 25
const SEARCH_DEBOUNCE_MS = 300
const ALL = 'all'

type FilterItem = { value: string; label: string }

const STATUS_ITEMS: FilterItem[] = [
  { value: ALL, label: 'Todos los estados' },
  ...ASSET_STATUSES.map((status) => ({ value: status, label: ASSET_STATUS_LABELS[status] })),
]

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

const muted = <span className="text-muted-foreground">—</span>

const COLUMNS: ColumnDef<AssetRecord>[] = [
  {
    id: 'plate',
    header: 'Placa',
    cell: ({ row }) => (
      <Link href={`/activos/${row.original.id}`} className="font-medium tabular-nums underline-offset-4 hover:underline">
        {row.original.plate}
      </Link>
    ),
  },
  {
    id: 'type',
    header: 'Tipo',
    cell: ({ row }) => (
      <div className="flex flex-col">
        <span>{row.original.asset_type.name}</span>
        <span className="text-xs text-muted-foreground">{row.original.asset_type.category.name}</span>
      </div>
    ),
  },
  {
    id: 'brand_model',
    header: 'Marca / Modelo',
    cell: ({ row }) => {
      const parts = [row.original.brand, row.original.model].filter(Boolean)
      return parts.length > 0 ? parts.join(' · ') : muted
    },
  },
  {
    id: 'status',
    header: 'Estado',
    cell: ({ row }) => <AssetStatusBadge status={row.original.status} />,
  },
  {
    id: 'location',
    header: 'Ubicación',
    cell: ({ row }) =>
      row.original.location ? (
        <div className="flex flex-wrap items-center gap-1.5">
          <span>{row.original.location.name}</span>
          {!row.original.location.is_active && <Badge variant="secondary">Inactiva</Badge>}
        </div>
      ) : (
        muted
      ),
  },
  {
    id: 'holder',
    header: 'Lo tiene',
    // RN-039: quién lo tiene (lo fija Entregas).
    cell: ({ row }) => row.original.holder?.full_name ?? muted,
  },
  {
    id: 'next_maintenance',
    header: 'Próximo mantenimiento',
    cell: ({ row }) => (row.original.next_maintenance_on ? formatDate(row.original.next_maintenance_on) : muted),
  },
]

// Módulo Activos (spec 005): ver = activos.ver; registrar = activos.gestionar (FR-007).
export function ActivosScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('activos.ver')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">Activos</h1>
        <p className="text-sm text-muted-foreground">Bienes serializados de la estación: estado, ubicación, mantenimientos y vencimientos.</p>
      </div>
      <AssetAlertsCard />
      <AssetsList />
    </div>
  )
}

// US1-5: listado paginado por el servidor con búsqueda (placa, serial, marca,
// modelo) y filtros de categoría, tipo, estado y ubicación.
function AssetsList() {
  const canManage = useCan('activos.gestionar')
  const { options, failed: optionsFailed } = useConfigurationOptions()

  const [records, setRecords] = useState<AssetRecord[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [categoryFilter, setCategoryFilter] = useState(ALL)
  const [typeFilter, setTypeFilter] = useState(ALL)
  const [statusFilter, setStatusFilter] = useState(ALL)
  const [locationFilter, setLocationFilter] = useState(ALL)
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [isFormOpen, setIsFormOpen] = useState(false)

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  useEffect(() => {
    const timeout = setTimeout(() => {
      setSearch(searchInput)
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timeout)
  }, [searchInput])

  useEffect(() => {
    let cancelled = false
    assetsApi
      .list({
        search,
        assetCategoryId: idFilter(categoryFilter),
        assetTypeId: idFilter(typeFilter),
        status: statusFilter === ALL ? undefined : (statusFilter as AssetStatus),
        locationId: idFilter(locationFilter),
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
  }, [search, categoryFilter, typeFilter, statusFilter, locationFilter, page, reloadKey])

  // Los filtros ofrecen lo disponible (activo); lo inactivo se ve con "Todos".
  const categoryItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todas las categorías' },
      ...(options?.asset_categories ?? []).map((category) => ({ value: String(category.id), label: category.name })),
    ],
    [options]
  )
  // RN-007: solo tipos serializados; si hay categoría elegida, solo los suyos.
  const typeItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todos los tipos' },
      ...(options?.asset_types ?? [])
        .filter((type) => type.tracking_mode === 'serialized')
        .filter((type) => categoryFilter === ALL || String(type.asset_category_id) === categoryFilter)
        .map((type) => ({ value: String(type.id), label: type.name })),
    ],
    [options, categoryFilter]
  )
  const locationItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todas las ubicaciones' },
      ...(options?.locations ?? []).map((location) => ({ value: String(location.id), label: location.name })),
    ],
    [options]
  )

  function changeFilter(setter: (value: string) => void) {
    return (value: string) => {
      setter(value)
      setPage(1)
    }
  }

  function handleSaved(record: AssetRecord) {
    setIsFormOpen(false)
    toast.success(`Activo "${record.plate}" registrado.`)
    reload()
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <Input
          type="search"
          aria-label="Buscar activos"
          placeholder="Buscar por placa, serial, marca o modelo"
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="sm:max-w-72"
        />
        <FilterSelect
          label="Filtrar por categoría"
          items={categoryItems}
          value={categoryFilter}
          onChange={changeFilter((value) => {
            setCategoryFilter(value)
            setTypeFilter(ALL)
          })}
        />
        <FilterSelect label="Filtrar por tipo" items={typeItems} value={typeFilter} onChange={changeFilter(setTypeFilter)} />
        <FilterSelect label="Filtrar por estado" items={STATUS_ITEMS} value={statusFilter} onChange={changeFilter(setStatusFilter)} />
        <FilterSelect label="Filtrar por ubicación" items={locationItems} value={locationFilter} onChange={changeFilter(setLocationFilter)} />
        {canManage && (
          <Button className="sm:ml-auto" onClick={() => setIsFormOpen(true)}>
            <PlusIcon data-icon="inline-start" />
            Nuevo activo
          </Button>
        )}
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar los activos.
        </p>
      ) : records === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <div className="overflow-x-auto">
          <DataTable
            columns={COLUMNS}
            data={records}
            getRowId={(record) => String(record.id)}
            emptyMessage="No hay activos que coincidan con los filtros."
          />
        </div>
      )}

      <Pager meta={meta} singular="activo" plural="activos" onPage={setPage} />

      {canManage && (
        <AssetFormDialog
          open={isFormOpen}
          onOpenChange={setIsFormOpen}
          asset={null}
          options={options}
          optionsFailed={optionsFailed}
          onSaved={handleSaved}
        />
      )}
    </div>
  )
}
