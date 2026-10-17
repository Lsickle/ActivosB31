'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { PlusIcon } from 'lucide-react'
import { toast } from 'sonner'
import { inventoryApi, type MovementRecord } from 'app/features/inventario/api'
import { MOVEMENT_TYPES, MOVEMENT_TYPE_LABELS, type MovementType } from 'app/features/inventario/schemas'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { useCan } from 'app/provider/auth'
import { DataTable } from '@/components/data-table'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDateTime } from '@/features/activos/format'
import { Pager } from '@/features/activos/Pager'
import { MovementFormDialog } from './MovementFormDialog'
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

const MOVEMENT_TYPE_ITEMS: FilterItem[] = [
  { value: ALL, label: 'Todas las clases' },
  ...MOVEMENT_TYPES.map((type) => ({ value: type, label: MOVEMENT_TYPE_LABELS[type] })),
]

// Referencia al origen del movimiento: conteo (RN-027) o entrega (007).
function reference(movement: MovementRecord): string | null {
  if (movement.count_id) return `Conteo #${movement.count_id}`
  if (movement.delivery_id) return `Entrega #${movement.delivery_id}`
  return null
}

const COLUMNS: ColumnDef<MovementRecord>[] = [
  {
    id: 'created_at',
    header: 'Fecha',
    cell: ({ row }) => <span className="whitespace-nowrap tabular-nums">{formatDateTime(row.original.created_at)}</span>,
  },
  {
    id: 'type',
    header: 'Movimiento',
    cell: ({ row }) => row.original.type_label || MOVEMENT_TYPE_LABELS[row.original.type],
  },
  { id: 'asset_type', header: 'Tipo de artículo', cell: ({ row }) => row.original.asset_type.name },
  {
    id: 'quantity',
    header: () => <span className="block text-right">Cantidad</span>,
    cell: ({ row }) => <span className="block text-right tabular-nums">{formatQuantity(row.original.quantity)}</span>,
  },
  { id: 'from', header: 'Origen', cell: ({ row }) => row.original.from_location?.name ?? muted },
  { id: 'to', header: 'Destino', cell: ({ row }) => row.original.to_location?.name ?? muted },
  {
    id: 'reason',
    header: 'Motivo',
    cell: ({ row }) => {
      const ref = reference(row.original)
      if (!row.original.reason && !ref) return muted
      return (
        <div className="flex flex-col">
          {row.original.reason && <span>{row.original.reason}</span>}
          {ref && <span className="text-xs text-muted-foreground">{ref}</span>}
        </div>
      )
    },
  },
  { id: 'user', header: 'Registró', cell: ({ row }) => row.original.user?.name ?? muted },
]

// US1 / FR-001 / FR-003: historial de movimientos (inmutable, D-002) con
// filtros, y registro de entradas, salidas, traslados y ajustes (inventario.gestionar).
export function MovementsTab({ options, optionsFailed }: OptionsProps) {
  const canManage = useCan('inventario.gestionar')

  const [records, setRecords] = useState<MovementRecord[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)

  const [typeFilter, setTypeFilter] = useState(ALL)
  const [assetTypeFilter, setAssetTypeFilter] = useState(ALL)
  const [locationFilter, setLocationFilter] = useState(ALL)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)
  const [isFormOpen, setIsFormOpen] = useState(false)

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  useEffect(() => {
    let cancelled = false
    inventoryApi.movements
      .list({
        type: typeFilter === ALL ? undefined : (typeFilter as MovementType),
        assetTypeId: idFilter(assetTypeFilter),
        locationId: idFilter(locationFilter),
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
  }, [typeFilter, assetTypeFilter, locationFilter, from, to, page, reloadKey])

  const assetTypeItems = useMemo<FilterItem[]>(
    () => [
      { value: ALL, label: 'Todos los tipos' },
      ...quantityTypes(options).map((type) => ({ value: String(type.id), label: type.name })),
    ],
    [options]
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

  function handleSaved() {
    setIsFormOpen(false)
    toast.success('Movimiento registrado.')
    setPage(1)
    reload()
  }

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">
        Los movimientos no se editan ni se borran: un error se corrige registrando el movimiento inverso con su motivo.
      </p>
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-end">
        <FilterSelect label="Filtrar por clase de movimiento" items={MOVEMENT_TYPE_ITEMS} value={typeFilter} onChange={changeFilter(setTypeFilter)} />
        <FilterSelect label="Filtrar por tipo de artículo" items={assetTypeItems} value={assetTypeFilter} onChange={changeFilter(setAssetTypeFilter)} />
        <FilterSelect label="Filtrar por ubicación" items={locationItems} value={locationFilter} onChange={changeFilter(setLocationFilter)} />
        <div className="flex items-center gap-2">
          <Label htmlFor="movements-from" className="text-muted-foreground">
            Desde
          </Label>
          <Input
            id="movements-from"
            type="date"
            value={from}
            max={to || undefined}
            onChange={(event) => changeFilter(setFrom)(event.target.value)}
            className="sm:w-40"
          />
          <Label htmlFor="movements-to" className="text-muted-foreground">
            Hasta
          </Label>
          <Input
            id="movements-to"
            type="date"
            value={to}
            min={from || undefined}
            onChange={(event) => changeFilter(setTo)(event.target.value)}
            className="sm:w-40"
          />
        </div>
        {canManage && (
          <Button className="sm:ml-auto" onClick={() => setIsFormOpen(true)}>
            <PlusIcon data-icon="inline-start" />
            Registrar movimiento
          </Button>
        )}
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar los movimientos.
        </p>
      ) : records === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <div className="overflow-x-auto">
          <DataTable
            columns={COLUMNS}
            data={records}
            getRowId={(record) => String(record.id)}
            emptyMessage="No hay movimientos que coincidan con los filtros."
          />
        </div>
      )}

      <Pager meta={meta} singular="movimiento" plural="movimientos" onPage={setPage} />

      {canManage && (
        <MovementFormDialog open={isFormOpen} onOpenChange={setIsFormOpen} options={options} optionsFailed={optionsFailed} onSaved={handleSaved} />
      )}
    </div>
  )
}
