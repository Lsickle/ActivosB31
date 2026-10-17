'use client'

import { useEffect, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { inventoryApi, type SerializedSummaryRow } from 'app/features/inventario/api'
import { DataTable } from '@/components/data-table'
import { Skeleton } from '@/components/ui/skeleton'
import { AssetStatusBadge } from '@/features/activos/AssetStatusBadge'
import { formatQuantity } from './shared'

const COLUMNS: ColumnDef<SerializedSummaryRow>[] = [
  { id: 'type', header: 'Tipo de activo', cell: ({ row }) => <span className="font-medium">{row.original.asset_type.name}</span> },
  {
    id: 'location',
    header: 'Ubicación',
    cell: ({ row }) => row.original.location?.name ?? <span className="text-muted-foreground">Sin ubicación</span>,
  },
  { id: 'status', header: 'Estado', cell: ({ row }) => <AssetStatusBadge status={row.original.status} /> },
  {
    id: 'count',
    header: () => <span className="block text-right">Cantidad</span>,
    cell: ({ row }) => <span className="block text-right tabular-nums">{formatQuantity(row.original.count)}</span>,
  },
]

// US2 / D-005: los activos serializados agrupados por tipo, ubicación y estado.
// El detalle de cada activo está en el módulo Activos.
export function SerializedTab() {
  const [rows, setRows] = useState<SerializedSummaryRow[] | null>(null)
  const [loadError, setLoadError] = useState(false)

  useEffect(() => {
    let cancelled = false
    inventoryApi
      .serializedSummary()
      .then(({ data }) => {
        if (!cancelled) setRows(data)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  if (loadError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        No fue posible cargar el resumen de serializados.
      </p>
    )
  }

  if (rows === null) return <Skeleton className="h-48 w-full" />

  return (
    <div className="flex flex-col gap-3">
      <p className="text-sm text-muted-foreground">Activos controlados uno a uno; el detalle de cada uno está en el módulo Activos.</p>
      <div className="overflow-x-auto">
        <DataTable
          columns={COLUMNS}
          data={rows}
          getRowId={(row) => `${row.asset_type.id}-${row.location?.id ?? 'none'}-${row.status}`}
          emptyMessage="No hay activos serializados registrados."
        />
      </div>
    </div>
  )
}
