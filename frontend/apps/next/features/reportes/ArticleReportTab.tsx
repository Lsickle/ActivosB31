'use client'

import { useMemo, useState } from 'react'
import {
  reportsApi,
  type AssetOption,
  type AssetReport,
  type AssetReportEvent,
  type AssetTypeReport,
  type AssetTypeReportMovement,
  type AssetTypeReportOption,
  type ReportRequest,
} from 'app/features/reportes/api'
import { dateRangeError } from 'app/features/reportes/schemas'
import { Badge } from '@/components/ui/badge'
import { TableCell } from '@/components/ui/table'
import { AssetStatusBadge } from '@/features/activos/AssetStatusBadge'
import { formatDate, formatDateTime } from '@/features/activos/format'
import {
  ALL,
  FilterSelect,
  activeLocationItems,
  idFilter,
  type FilterItem,
  type OptionsProps,
} from '@/features/inventario/shared'
import {
  DateRangeFields,
  ExportButtons,
  OptionSearch,
  ReportBody,
  ReportHeader,
  ReportTable,
  formatNumber,
  muted,
  numeric,
  plural,
  useReport,
  type ReportColumn,
} from './shared'

type Mode = 'serialized' | 'quantity'

const MODE_ITEMS: FilterItem[] = [
  { value: 'serialized', label: 'Activo serializado' },
  { value: 'quantity', label: 'Tipo por cantidad' },
]

const EVENT_COLUMNS: ReportColumn<AssetReportEvent>[] = [
  { header: 'Fecha', cell: (row) => formatDateTime(row.created_at) },
  { header: 'Evento', cell: (row) => row.event_label },
  { header: 'Detalle', cell: (row) => row.detail ?? muted },
  { header: 'Usuario', cell: (row) => row.user?.name ?? muted },
]

const STOCK_COLUMNS: ReportColumn<AssetTypeReport['stock'][number]>[] = [
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
]

const MOVEMENT_COLUMNS: ReportColumn<AssetTypeReportMovement>[] = [
  { header: 'Fecha', cell: (row) => formatDateTime(row.created_at) },
  { header: 'Clase', cell: (row) => row.type_label },
  { header: 'Cantidad', numeric: true, cell: (row) => formatNumber(row.quantity) },
  { header: 'Origen', cell: (row) => row.from_location?.name ?? muted },
  { header: 'Destino', cell: (row) => row.to_location?.name ?? muted },
  { header: 'Detalle', cell: (row) => row.delivery?.code ?? row.reason ?? muted },
  { header: 'Usuario', cell: (row) => row.user?.name ?? muted },
]

const loadAsset = (request: ReportRequest) => {
  const { assetId, range } = request as Extract<ReportRequest, { kind: 'asset' }>
  return reportsApi.asset(assetId, range)
}

const loadAssetType = (request: ReportRequest) => {
  const { assetTypeId, filters } = request as Extract<ReportRequest, { kind: 'asset-type' }>
  return reportsApi.assetType(assetTypeId, filters)
}

// US3: activo serializado (ficha + historial) o tipo por cantidad (existencias
// por ubicación + movimientos), con rango de fechas (RN-033).
export function ArticleReportTab({ options, optionsFailed }: OptionsProps) {
  const [mode, setMode] = useState<Mode>('serialized')

  return (
    <div className="flex flex-col gap-4">
      <FilterSelect label="Clase de artículo" items={MODE_ITEMS} value={mode} onChange={(value) => setMode(value === 'quantity' ? 'quantity' : 'serialized')} />
      {mode === 'serialized' ? <AssetReportSection /> : <AssetTypeReportSection options={options} optionsFailed={optionsFailed} />}
    </div>
  )
}

// ---- Activo serializado ----

function AssetCard({ asset }: { asset: AssetReport['asset'] }) {
  const fields: { label: string; value: React.ReactNode }[] = [
    { label: 'Tipo', value: `${asset.asset_type.name} (${asset.asset_type.category.name})` },
    { label: 'Estado', value: <AssetStatusBadge status={asset.status} /> },
    { label: 'Serial', value: asset.serial ?? '—' },
    { label: 'Marca / modelo', value: [asset.brand, asset.model].filter(Boolean).join(' / ') || '—' },
    { label: 'Ubicación', value: asset.location?.name ?? '—' },
    { label: 'Lo tiene', value: asset.holder ? `${asset.holder.full_name} · ${asset.holder.position}` : '—' },
    { label: 'Adquisición', value: formatDate(asset.acquisition_date) },
    { label: 'Vence', value: formatDate(asset.expires_on) },
    { label: 'Próximo mantenimiento', value: formatDate(asset.next_maintenance_on) },
  ]
  return (
    <div className="flex flex-col gap-2 rounded-lg border p-3">
      <h2 className="font-medium tabular-nums">{asset.plate}</h2>
      <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
        {fields.map((field) => (
          <div key={field.label} className="flex items-center gap-2">
            <dt className="text-muted-foreground">{field.label}:</dt>
            <dd>{field.value}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}

function AssetReportSection() {
  const [selected, setSelected] = useState<AssetOption | null>(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const rangeError = dateRangeError(from, to)
  const request = useMemo<ReportRequest | null>(
    () => (selected && !rangeError ? { kind: 'asset', assetId: selected.id, range: { from: from || undefined, to: to || undefined } } : null),
    [selected, from, to, rangeError]
  )
  const { report, loading, error } = useReport<AssetReport>(request, loadAsset)

  return (
    <div className="flex flex-col gap-4">
      <OptionSearch<AssetOption>
        label="Buscar activo por placa"
        placeholder="Buscar por placa o serial"
        search={reportsApi.assetOptions}
        itemKey={(item) => String(item.id)}
        itemName={(item) => item.plate}
        itemDetail={(item) => `${item.asset_type.name} · ${item.status_label}`}
        onSelect={setSelected}
        empty="No hay activos con esa placa o serial."
        failed="No fue posible buscar activos."
      />
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        {selected && (
          <p className="text-sm">
            Activo: <span className="font-medium tabular-nums">{selected.plate}</span>{' '}
            <span className="text-muted-foreground">· {selected.asset_type.name}</span>
          </p>
        )}
        <DateRangeFields idPrefix="report-asset" from={from} to={to} onFrom={setFrom} onTo={setTo} />
        <div className="sm:ml-auto">
          <ExportButtons request={request} ready={report !== null} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">Incluye activos de cualquier estado, también los dados de baja. El rango aplica al historial.</p>
      {rangeError && (
        <p role="alert" className="text-sm text-destructive">
          {rangeError}
        </p>
      )}
      <ReportBody report={report} loading={loading} error={error} idle={rangeError ? undefined : 'Busca un activo por su placa para ver su reporte.'}>
        {(data) => (
          <div className="flex flex-col gap-6">
            <ReportHeader meta={data} />
            <AssetCard asset={data.asset} />
            <ReportTable
              id="report-asset-events"
              title="Historial del activo"
              columns={EVENT_COLUMNS}
              rows={data.events}
              rowKey={(row) => String(row.id)}
              empty="No hay eventos en el rango."
              footer={
                <TableCell colSpan={EVENT_COLUMNS.length - 1} className="text-right">
                  {plural(data.totals.events, 'evento', 'eventos')}
                </TableCell>
              }
            />
          </div>
        )}
      </ReportBody>
    </div>
  )
}

// ---- Tipo por cantidad ----

function AssetTypeReportSection({ options }: OptionsProps) {
  const [selected, setSelected] = useState<AssetTypeReportOption | null>(null)
  const [locationFilter, setLocationFilter] = useState(ALL)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const locationItems = useMemo<FilterItem[]>(
    () => [{ value: ALL, label: 'Todas las ubicaciones' }, ...activeLocationItems(options)],
    [options]
  )

  const rangeError = dateRangeError(from, to)
  const request = useMemo<ReportRequest | null>(
    () =>
      selected && !rangeError
        ? {
            kind: 'asset-type',
            assetTypeId: selected.id,
            filters: { from: from || undefined, to: to || undefined, locationId: idFilter(locationFilter) },
          }
        : null,
    [selected, locationFilter, from, to, rangeError]
  )
  const { report, loading, error } = useReport<AssetTypeReport>(request, loadAssetType)

  return (
    <div className="flex flex-col gap-4">
      <OptionSearch<AssetTypeReportOption>
        label="Buscar tipo de artículo"
        placeholder="Buscar tipo por cantidad"
        search={reportsApi.assetTypeOptions}
        itemKey={(item) => String(item.id)}
        itemName={(item) => item.name}
        itemDetail={(item) => item.category.name}
        inactive={(item) => !item.is_active}
        onSelect={setSelected}
        empty="No hay tipos por cantidad que coincidan."
        failed="No fue posible buscar tipos de artículo."
      />
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        {selected && (
          <p className="text-sm">
            Tipo: <span className="font-medium">{selected.name}</span>{' '}
            <span className="text-muted-foreground">
              · {selected.category.name}
              {!selected.is_active && ' (inactivo)'}
            </span>
          </p>
        )}
        <FilterSelect label="Filtrar por ubicación" items={locationItems} value={locationFilter} onChange={setLocationFilter} />
        <DateRangeFields idPrefix="report-asset-type" from={from} to={to} onFrom={setFrom} onTo={setTo} />
        <div className="sm:ml-auto">
          <ExportButtons request={request} ready={report !== null} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">Las existencias son las actuales; el rango aplica a los movimientos.</p>
      {rangeError && (
        <p role="alert" className="text-sm text-destructive">
          {rangeError}
        </p>
      )}
      <ReportBody report={report} loading={loading} error={error} idle={rangeError ? undefined : 'Busca un tipo de artículo por cantidad.'}>
        {(data) => (
          <div className="flex flex-col gap-6">
            <ReportHeader meta={data} />
            <div className="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border p-3 text-sm">
              <h2 className="font-medium">
                {data.asset_type.name} <span className="font-normal text-muted-foreground">({data.asset_type.category.name})</span>
              </h2>
              <span>
                <span className="text-muted-foreground">Total: </span>
                <span className="tabular-nums">{formatNumber(data.totals.stock_total)}</span>
              </span>
              <span>
                <span className="text-muted-foreground">Stock mínimo: </span>
                {data.asset_type.min_stock === null ? '—' : <span className="tabular-nums">{formatNumber(data.asset_type.min_stock)}</span>}
              </span>
              {data.totals.below_min && <Badge variant="destructive">Bajo mínimo</Badge>}
            </div>
            <ReportTable
              id="report-asset-type-stock"
              title="Existencias por ubicación"
              columns={STOCK_COLUMNS}
              rows={data.stock}
              rowKey={(row) => String(row.location.id)}
              empty="No hay existencias de este tipo."
              footer={<TableCell className={numeric}>{formatNumber(data.totals.stock_total)}</TableCell>}
            />
            <ReportTable
              id="report-asset-type-movements"
              title="Movimientos"
              columns={MOVEMENT_COLUMNS}
              rows={data.movements}
              rowKey={(row) => String(row.id)}
              empty="No hay movimientos en el rango."
              footer={
                <TableCell colSpan={MOVEMENT_COLUMNS.length - 1} className="text-right">
                  {plural(data.totals.movements, 'movimiento', 'movimientos')}
                </TableCell>
              }
            />
          </div>
        )}
      </ReportBody>
    </div>
  )
}
