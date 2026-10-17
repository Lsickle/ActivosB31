'use client'

import { useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { inventoryApi, type StockRow } from 'app/features/inventario/api'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { DataTable } from '@/components/data-table'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Pager } from '@/features/activos/Pager'
import {
  ALL,
  FilterSelect,
  PER_PAGE,
  activeLocationItems,
  formatQuantity,
  idFilter,
  muted,
  quantityTypes,
  type FilterItem,
  type OptionsProps,
} from './shared'

const numeric = 'text-right tabular-nums'

const COLUMNS: ColumnDef<StockRow>[] = [
  {
    id: 'type',
    header: 'Tipo de artículo',
    cell: ({ row }) => (
      <div className="flex flex-col">
        <span className="font-medium">{row.original.asset_type.name}</span>
        <span className="text-xs text-muted-foreground">{row.original.asset_type.category.name}</span>
      </div>
    ),
  },
  {
    id: 'location',
    header: 'Ubicación',
    cell: ({ row }) => (
      <div className="flex flex-wrap items-center gap-1.5">
        <span>{row.original.location.name}</span>
        {!row.original.location.is_active && <Badge variant="secondary">Inactiva</Badge>}
      </div>
    ),
  },
  {
    id: 'quantity',
    header: () => <span className="block text-right">Existencia</span>,
    cell: ({ row }) => <span className={`block ${numeric}`}>{formatQuantity(row.original.quantity)}</span>,
  },
  {
    id: 'type_total',
    header: () => <span className="block text-right">Total del tipo</span>,
    cell: ({ row }) => <span className={`block ${numeric}`}>{formatQuantity(row.original.type_total)}</span>,
  },
  {
    id: 'min_stock',
    header: () => <span className="block text-right">Stock mínimo</span>,
    cell: ({ row }) =>
      row.original.min_stock === null ? (
        <span className="block text-right">{muted}</span>
      ) : (
        <span className={`block ${numeric}`}>{formatQuantity(row.original.min_stock)}</span>
      ),
  },
  {
    id: 'alert',
    header: 'Alerta',
    // RN-028: el total del tipo está por debajo de su stock mínimo.
    cell: ({ row }) => (row.original.below_min ? <Badge variant="destructive">Bajo mínimo</Badge> : null),
  },
]

// US2: existencias por tipo (por cantidad) y ubicación, con total por tipo y
// alerta de stock mínimo (RN-028). Filtros de FR-003.
export function StockTab({ options }: OptionsProps) {
  const [rows, setRows] = useState<StockRow[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)

  const [categoryFilter, setCategoryFilter] = useState(ALL)
  const [typeFilter, setTypeFilter] = useState(ALL)
  const [locationFilter, setLocationFilter] = useState(ALL)
  const [belowMin, setBelowMin] = useState(false)
  const [page, setPage] = useState(1)

  useEffect(() => {
    let cancelled = false
    inventoryApi
      .stock({
        assetCategoryId: idFilter(categoryFilter),
        assetTypeId: idFilter(typeFilter),
        locationId: idFilter(locationFilter),
        belowMin,
        page,
        perPage: PER_PAGE,
      })
      .then((response) => {
        if (cancelled) return
        setRows(response.data)
        setMeta(response.meta)
        setLoadError(false)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [categoryFilter, typeFilter, locationFilter, belowMin, page])

  const categoryItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todas las categorías' },
      ...(options?.asset_categories ?? []).map((category) => ({ value: String(category.id), label: category.name })),
    ],
    [options]
  )
  const typeItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todos los tipos' },
      ...quantityTypes(options, categoryFilter).map((type) => ({ value: String(type.id), label: type.name })),
    ],
    [options, categoryFilter]
  )
  const locationItems = useMemo<FilterItem[]>(
    () => [{ value: ALL, label: 'Todas las ubicaciones' }, ...activeLocationItems(options)],
    [options]
  )

  function changeFilter(setter: (value: string) => void) {
    return (value: string) => {
      setter(value)
      setPage(1)
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
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
        <FilterSelect label="Filtrar por ubicación" items={locationItems} value={locationFilter} onChange={changeFilter(setLocationFilter)} />
        <div className="flex items-center gap-2 sm:ml-2">
          <Checkbox
            id="stock-below-min"
            checked={belowMin}
            onCheckedChange={(checked) => {
              setBelowMin(checked === true)
              setPage(1)
            }}
          />
          <Label htmlFor="stock-below-min">Solo bajo mínimo</Label>
        </div>
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar las existencias.
        </p>
      ) : rows === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <div className="overflow-x-auto">
          <DataTable
            columns={COLUMNS}
            data={rows}
            getRowId={(row) => `${row.asset_type.id}-${row.location.id}`}
            emptyMessage="No hay existencias que coincidan con los filtros."
          />
        </div>
      )}

      <Pager meta={meta} singular="fila" plural="filas" onPage={setPage} />
    </div>
  )
}
