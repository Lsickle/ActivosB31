'use client'

import { useMemo, useState } from 'react'
import {
  reportsApi,
  type EmployeeReport,
  type EmployeeReportEmployee,
  type EmployeeReportOption,
  type EmployeeReportHistoryRow,
  type EmployeeReportHolding,
  type ReportRequest,
} from 'app/features/reportes/api'
import { dateRangeError } from 'app/features/reportes/schemas'
import { Badge } from '@/components/ui/badge'
import { TableCell } from '@/components/ui/table'
import { formatDate, formatDateTime } from '@/features/activos/format'
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
  useReport,
  type ReportColumn,
} from './shared'

function ArticleCell({ typeName, plate }: { typeName: string; plate: string | null }) {
  return (
    <div className="flex flex-col">
      <span className="font-medium">{typeName}</span>
      {plate && <span className="text-xs text-muted-foreground tabular-nums">{plate}</span>}
    </div>
  )
}

const HOLDING_COLUMNS: ReportColumn<EmployeeReportHolding>[] = [
  { header: 'Entrega', cell: (row) => <span className="tabular-nums">{row.delivery.code}</span> },
  { header: 'Tipo', cell: (row) => row.delivery.kind_label },
  { header: 'Artículo', cell: (row) => <ArticleCell typeName={row.asset_type.name} plate={row.asset?.plate ?? null} /> },
  { header: 'Entregado', cell: (row) => formatDateTime(row.delivered_at) },
  {
    header: 'Devolución esperada',
    cell: (row) =>
      row.delivery.due_on ? (
        <div className="flex flex-wrap items-center gap-1.5">
          <span>{formatDate(row.delivery.due_on)}</span>
          {row.delivery.status === 'overdue' && <Badge variant="destructive">Vencida</Badge>}
        </div>
      ) : (
        muted
      ),
  },
  { header: 'Pendiente', numeric: true, cell: (row) => formatNumber(row.pending_quantity) },
]

const HISTORY_COLUMNS: ReportColumn<EmployeeReportHistoryRow>[] = [
  { header: 'Fecha', cell: (row) => formatDateTime(row.date) },
  { header: 'Movimiento', cell: (row) => row.event_label },
  { header: 'Entrega', cell: (row) => <span className="tabular-nums">{`${row.delivery.code} · ${row.delivery.kind_label}`}</span> },
  { header: 'Artículo', cell: (row) => <ArticleCell typeName={row.asset_type.name} plate={row.asset?.plate ?? null} /> },
  { header: 'Estado', cell: (row) => row.condition_label ?? muted },
  { header: 'Cantidad', numeric: true, cell: (row) => formatNumber(row.quantity) },
]

// RN-022: documento y contacto solo vienen con empleados.ver; si no vienen, no se muestran.
function EmployeeCard({ employee }: { employee: EmployeeReportEmployee }) {
  const hasPersonalData = 'document_number' in employee
  const fields: { label: string; value: React.ReactNode }[] = [{ label: 'Cargo', value: employee.position }]
  if (employee.company) fields.push({ label: 'Compañía', value: employee.company })
  if (hasPersonalData) {
    fields.push({ label: 'Documento', value: `${employee.document_type ?? ''} ${employee.document_number ?? ''}`.trim() })
    if (employee.phone) fields.push({ label: 'Teléfono', value: employee.phone })
    if (employee.email) fields.push({ label: 'Correo', value: employee.email })
  }

  return (
    <div className="flex flex-col gap-2 rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <h2 className="font-medium">{employee.full_name}</h2>
        {!employee.is_active && <Badge variant="secondary">Inactivo</Badge>}
      </div>
      <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3">
        {fields.map((field) => (
          <div key={field.label} className="flex gap-2">
            <dt className="text-muted-foreground">{field.label}:</dt>
            <dd>{field.value}</dd>
          </div>
        ))}
      </dl>
      {!hasPersonalData && (
        <p className="text-xs text-muted-foreground">Los datos personales solo se muestran con el permiso para ver empleados.</p>
      )}
    </div>
  )
}

const loadEmployee = (request: ReportRequest) => {
  const { employeeId, range } = request as Extract<ReportRequest, { kind: 'employee' }>
  return reportsApi.employee(employeeId, range)
}

// US2 / RN-033: lo que tiene hoy el empleado y su historial en el rango.
export function EmployeeReportTab() {
  const [selected, setSelected] = useState<EmployeeReportOption | null>(null)
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')

  const rangeError = dateRangeError(from, to)
  const request = useMemo<ReportRequest | null>(
    () =>
      selected && !rangeError
        ? { kind: 'employee', employeeId: selected.id, range: { from: from || undefined, to: to || undefined } }
        : null,
    [selected, from, to, rangeError]
  )
  const { report, loading, error } = useReport<EmployeeReport>(request, loadEmployee)

  return (
    <div className="flex flex-col gap-4">
      <OptionSearch<EmployeeReportOption>
        label="Buscar empleado"
        placeholder="Buscar por nombre"
        search={reportsApi.employeeOptions}
        itemKey={(item) => String(item.id)}
        itemName={(item) => item.full_name}
        itemDetail={(item) => item.position}
        inactive={(item) => !item.is_active}
        onSelect={setSelected}
        empty="No hay empleados que coincidan."
        failed="No fue posible buscar empleados."
      />
      <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        {selected && (
          <p className="text-sm">
            Empleado: <span className="font-medium">{selected.full_name}</span>{' '}
            <span className="text-muted-foreground">
              · {selected.position}
              {!selected.is_active && ' (inactivo)'}
            </span>
          </p>
        )}
        <DateRangeFields idPrefix="report-employee" from={from} to={to} onFrom={setFrom} onTo={setTo} />
        <div className="sm:ml-auto">
          <ExportButtons request={request} ready={report !== null} />
        </div>
      </div>
      <p className="text-xs text-muted-foreground">
        Incluye empleados inactivos. El rango de fechas aplica al historial; &quot;Lo que tiene hoy&quot; no depende del rango.
      </p>
      {rangeError && (
        <p role="alert" className="text-sm text-destructive">
          {rangeError}
        </p>
      )}

      <ReportBody report={report} loading={loading} error={error} idle={rangeError ? undefined : 'Busca un empleado para ver su reporte.'}>
        {(data) => (
          <div className="flex flex-col gap-6">
            <ReportHeader meta={data} />
            <EmployeeCard employee={data.employee} />
            <ReportTable
              id="report-employee-holdings"
              title="Lo que tiene hoy"
              columns={HOLDING_COLUMNS}
              rows={data.holdings}
              rowKey={(row, index) => `${row.delivery.id}-${row.asset?.id ?? row.asset_type.id}-${index}`}
              empty="No tiene artículos asignados ni prestados."
              footer={
                <>
                  <TableCell colSpan={HOLDING_COLUMNS.length - 2} />
                  <TableCell className={numeric}>{formatNumber(data.totals.pending_units)}</TableCell>
                </>
              }
            />
            <ReportTable
              id="report-employee-history"
              title="Historial de entregas y devoluciones"
              columns={HISTORY_COLUMNS}
              rows={data.history}
              rowKey={(row, index) => `${row.event}-${row.delivery.id}-${row.date}-${index}`}
              empty="No hay entregas ni devoluciones en el rango."
              footer={
                <TableCell colSpan={HISTORY_COLUMNS.length - 1} className="text-right">
                  Entregado: {formatNumber(data.totals.delivered_units)} · Devuelto: {formatNumber(data.totals.returned_units)}
                </TableCell>
              }
            />
          </div>
        )}
      </ReportBody>
    </div>
  )
}
