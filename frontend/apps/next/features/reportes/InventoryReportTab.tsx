'use client'

import { useMemo, useState } from 'react'
import { ASSET_STATUSES, ASSET_STATUS_LABELS, type AssetStatus } from 'app/features/activos/schemas'
import {
  reportsApi,
  type InventoryQuantityRow,
  type InventoryReport,
  type InventorySerializedRow,
  type ReportRequest,
} from 'app/features/reportes/api'
import { Badge } from '@/components/ui/badge'
import { TableCell } from '@/components/ui/table'
import { AssetStatusBadge } from '@/features/activos/AssetStatusBadge'
import { ALL, FilterSelect, activeLocationItems, idFilter, type FilterItem, type OptionsProps } from '@/features/inventario/shared'
import {
  ExportButtons,
  ReportBody,
  ReportHeader,
  ReportTable,
  formatNumber,
  muted,
  numeric,
  useReport,
  type ReportColumn,
} from './shared'

function TypeCell({ name, category }: { name: string; category: string }) {
  return (
    <div className="flex flex-col">
      <span className="font-medium">{name}</span>
      <span className="text-xs text-muted-foreground">{category}</span>
    </div>
  )
}

const SERIALIZED_COLUMNS: ReportColumn<InventorySerializedRow>[] = [
  { header: 'Tipo de artículo', cell: (row) => <TypeCell name={row.asset_type.name} category={row.asset_type.category.name} /> },
  // Asignados (RN-039) quedan sin ubicación: los tiene un empleado.
  { header: 'Ubicación', cell: (row) => row.location?.name ?? <span className="text-muted-foreground">Sin ubicación</span> },
  { header: 'Estado', cell: (row) => <AssetStatusBadge status={row.status} /> },
  { header: 'Cantidad', numeric: true, cell: (row) => formatNumber(row.count) },
]

const QUANTITY_COLUMNS: ReportColumn<InventoryQuantityRow>[] = [
  { header: 'Tipo de artículo', cell: (row) => <TypeCell name={row.asset_type.name} category={row.asset_type.category.name} /> },
  {
    header: 'Ubicación',
    cell: (row) => (
      <div className="flex flex-wrap items-center gap-1.5">
        <span>{row.location.name}</span>
        {!row.location.is_active && <Badge variant="secondary">Inactiva</Badge>}
      </div>
    ),
  },
  { header: 'Existencia', numeric: true, cell: (row) => formatNumber(row.quantity) },
  { header: 'Total del tipo', numeric: true, cell: (row) => formatNumber(row.type_total) },
  { header: 'Stock mínimo', numeric: true, cell: (row) => (row.min_stock === null ? muted : formatNumber(row.min_stock)) },
  // RN-028: el total del tipo está por debajo de su mínimo.
  { header: 'Alerta', cell: (row) => (row.below_min ? <Badge variant="destructive">Bajo mínimo</Badge> : null) },
]

function SummaryItem({ label, value, detail }: { label: string; value: string; detail?: string }) {
  return (
    <div className="rounded-lg border p-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-xl font-semibold tabular-nums">{value}</dd>
      {detail && <dd className="text-xs text-muted-foreground">{detail}</dd>}
    </div>
  )
}

function statusBreakdown(report: InventoryReport): string | undefined {
  const parts = ASSET_STATUSES.filter((status) => report.totals.by_status[status] > 0).map(
    (status) => `${ASSET_STATUS_LABELS[status]}: ${formatNumber(report.totals.by_status[status])}`
  )
  return parts.length > 0 ? parts.join(' · ') : undefined
}

const STATUS_ITEMS: FilterItem[] = [
  { value: ALL, label: 'Todos los estados' },
  ...ASSET_STATUSES.map((status) => ({ value: status, label: ASSET_STATUS_LABELS[status] })),
]

const loadInventory = (request: ReportRequest) => reportsApi.inventory((request as Extract<ReportRequest, { kind: 'inventory' }>).filters)

// US1 / FR-001: existencias por categoría, tipo, ubicación y estado.
export function InventoryReportTab({ options }: OptionsProps) {
  const [categoryFilter, setCategoryFilter] = useState(ALL)
  const [typeFilter, setTypeFilter] = useState(ALL)
  const [locationFilter, setLocationFilter] = useState(ALL)
  const [statusFilter, setStatusFilter] = useState(ALL)

  const request = useMemo<ReportRequest>(
    () => ({
      kind: 'inventory',
      filters: {
        assetCategoryId: idFilter(categoryFilter),
        assetTypeId: idFilter(typeFilter),
        locationId: idFilter(locationFilter),
        status: statusFilter === ALL ? undefined : (statusFilter as AssetStatus),
      },
    }),
    [categoryFilter, typeFilter, locationFilter, statusFilter]
  )
  const { report, loading, error } = useReport<InventoryReport>(request, loadInventory)

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
      ...(options?.asset_types ?? [])
        .filter((type) => categoryFilter === ALL || String(type.asset_category_id) === categoryFilter)
        .map((type) => ({ value: String(type.id), label: type.name })),
    ],
    [options, categoryFilter]
  )
  const locationItems = useMemo<FilterItem[]>(
    () => [{ value: ALL, label: 'Todas las ubicaciones' }, ...activeLocationItems(options)],
    [options]
  )

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <FilterSelect
          label="Filtrar por categoría"
          items={categoryItems}
          value={categoryFilter}
          onChange={(value) => {
            setCategoryFilter(value)
            setTypeFilter(ALL)
          }}
        />
        <FilterSelect label="Filtrar por tipo" items={typeItems} value={typeFilter} onChange={setTypeFilter} />
        <FilterSelect label="Filtrar por ubicación" items={locationItems} value={locationFilter} onChange={setLocationFilter} />
        <FilterSelect label="Filtrar por estado" items={STATUS_ITEMS} value={statusFilter} onChange={setStatusFilter} />
        <div className="sm:ml-auto">
          <ExportButtons request={request} ready={report !== null} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">El estado aplica solo a los activos serializados.</p>

      <ReportBody report={report} loading={loading} error={error}>
        {(data) => (
          <div className="flex flex-col gap-6">
            <ReportHeader meta={data} />
            <dl className="grid gap-3 sm:grid-cols-3">
              <SummaryItem label="Activos serializados" value={formatNumber(data.totals.serialized)} detail={statusBreakdown(data)} />
              <SummaryItem label="Unidades por cantidad" value={formatNumber(data.totals.quantity_units)} />
              <SummaryItem label="Tipos bajo mínimo" value={formatNumber(data.totals.below_min_types)} />
            </dl>
            <ReportTable
              id="report-inventory-serialized"
              title="Activos serializados"
              columns={SERIALIZED_COLUMNS}
              rows={data.serialized}
              rowKey={(row) => `${row.asset_type.id}-${row.location?.id ?? 'none'}-${row.status}`}
              empty="No hay activos serializados que coincidan con los filtros."
              footer={
                <>
                  <TableCell />
                  <TableCell />
                  <TableCell className={numeric}>{formatNumber(data.totals.serialized)}</TableCell>
                </>
              }
            />
            <ReportTable
              id="report-inventory-quantity"
              title="Artículos por cantidad"
              columns={QUANTITY_COLUMNS}
              rows={data.quantity}
              rowKey={(row) => `${row.asset_type.id}-${row.location.id}`}
              empty="No hay existencias por cantidad que coincidan con los filtros."
              footer={
                <>
                  <TableCell />
                  <TableCell className={numeric}>{formatNumber(data.totals.quantity_units)}</TableCell>
                  <TableCell colSpan={3} className="text-xs font-normal text-muted-foreground">
                    {data.totals.below_min_types > 0
                      ? `${formatNumber(data.totals.below_min_types)} ${data.totals.below_min_types === 1 ? 'tipo' : 'tipos'} bajo mínimo`
                      : ''}
                  </TableCell>
                </>
              }
            />
          </div>
        )}
      </ReportBody>
    </div>
  )
}
