'use client'

import { useEffect, useState } from 'react'
import { FileSpreadsheetIcon, FileTextIcon, SearchIcon } from 'lucide-react'
import { toast } from 'sonner'
import { todayIsoDate } from 'app/features/empleados/schemas'
import { reportsApi, type ReportMeta, type ReportRequest } from 'app/features/reportes/api'
import { REPORT_FORMATS, REPORT_FORMAT_LABELS, reportFileName, type ReportFormat } from 'app/features/reportes/schemas'
import { ApiError, ApiValidationError, RateLimitError } from 'app/lib/api-client'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { cn } from '@/lib/utils'
import { formatDateTime } from '@/features/activos/format'
import { saveFile } from './download'

// Piezas comunes de las pestañas de Reportes (spec 008).

export const numeric = 'text-right tabular-nums'

const numberFormat = new Intl.NumberFormat('es-CO')

export function formatNumber(value: number): string {
  return numberFormat.format(value)
}

export function plural(count: number, singular: string, pluralWord: string): string {
  return `${formatNumber(count)} ${count === 1 ? singular : pluralWord}`
}

/** Mensaje para el usuario: el del servidor si es un error de la API; si no, uno genérico. */
export function errorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError || error instanceof ApiValidationError || error instanceof RateLimitError) return error.message
  return fallback
}

type ReportState<T> = { key: string; report: T | null; error: string | null }

/**
 * Carga el reporte de `request` (null = aún falta elegir algo). Devuelve el
 * reporte solo si corresponde a la petición vigente, para que los botones de
 * descarga nunca exporten filtros distintos a los que se ven (D-001).
 */
export function useReport<T>(
  request: ReportRequest | null,
  load: (request: ReportRequest) => Promise<{ data: T }>
): { report: T | null; loading: boolean; error: string | null } {
  const key = request ? JSON.stringify(request) : ''
  const [state, setState] = useState<ReportState<T> | null>(null)

  useEffect(() => {
    if (!request) return
    let cancelled = false
    load(request)
      .then(({ data }) => {
        if (!cancelled) setState({ key, report: data, error: null })
      })
      .catch((error: unknown) => {
        if (!cancelled) setState({ key, report: null, error: errorMessage(error, 'No fue posible generar el reporte.') })
      })
    return () => {
      cancelled = true
    }
    // `key` resume `request`; `load` es estable (función del módulo).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  if (!request) return { report: null, loading: false, error: null }
  const current = state && state.key === key ? state : null
  return { report: current?.report ?? null, loading: current === null, error: current?.error ?? null }
}

// ---- Exportación (RN-032, D-002) ----

const FORMAT_ICONS: Record<ReportFormat, React.ReactNode> = {
  xlsx: <FileSpreadsheetIcon data-icon="inline-start" />,
  pdf: <FileTextIcon data-icon="inline-start" />,
}

/** Botones "Excel" y "PDF": descargan el reporte visible con sus mismos filtros. */
export function ExportButtons({ request, ready }: { request: ReportRequest | null; ready: boolean }) {
  const [busy, setBusy] = useState<ReportFormat | null>(null)

  async function download(format: ReportFormat) {
    if (!request) return
    setBusy(format)
    try {
      const file = await reportsApi.download(request, format)
      saveFile(file, reportFileName(request.kind, format, todayIsoDate()))
    } catch (error) {
      toast.error(errorMessage(error, 'No fue posible descargar el reporte.'))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="flex gap-2" role="group" aria-label="Exportar reporte">
      {REPORT_FORMATS.map((format) => (
        <Button
          key={format}
          type="button"
          variant="outline"
          size="sm"
          aria-label={`Descargar en ${REPORT_FORMAT_LABELS[format]}`}
          disabled={!request || !ready || busy !== null}
          aria-busy={busy === format}
          onClick={() => download(format)}
        >
          {FORMAT_ICONS[format]}
          {busy === format ? 'Descargando…' : REPORT_FORMAT_LABELS[format]}
        </Button>
      ))}
    </div>
  )
}

// ---- Rango de fechas (RN-033) ----

export function DateRangeFields({
  idPrefix,
  from,
  to,
  onFrom,
  onTo,
}: {
  idPrefix: string
  from: string
  to: string
  onFrom: (value: string) => void
  onTo: (value: string) => void
}) {
  return (
    <div className="flex items-center gap-2">
      <Label htmlFor={`${idPrefix}-from`} className="text-muted-foreground">
        Desde
      </Label>
      <Input
        id={`${idPrefix}-from`}
        type="date"
        value={from}
        max={to || undefined}
        onChange={(event) => onFrom(event.target.value)}
        className="sm:w-40"
      />
      <Label htmlFor={`${idPrefix}-to`} className="text-muted-foreground">
        Hasta
      </Label>
      <Input
        id={`${idPrefix}-to`}
        type="date"
        value={to}
        min={from || undefined}
        onChange={(event) => onTo(event.target.value)}
        className="sm:w-40"
      />
    </div>
  )
}

// ---- Estado y encabezado del reporte ----

/** Fecha de generación, usuario y filtros: lo mismo que encabeza el Excel/PDF. */
export function ReportHeader({ meta }: { meta: ReportMeta }) {
  return (
    <div className="flex flex-col gap-1 text-xs text-muted-foreground">
      <p>
        Generado el {formatDateTime(meta.generated_at)} por {meta.generated_by.name}
      </p>
      {meta.filters.length > 0 && (
        <p>
          {meta.filters.map((filter, index) => (
            <span key={`${filter.label}-${index}`}>
              {index > 0 && ' · '}
              {filter.label}: <span className="text-foreground">{filter.value}</span>
            </span>
          ))}
        </p>
      )}
    </div>
  )
}

/** Error / cargando / contenido. `idle` = aún falta elegir algo. */
export function ReportBody<T>({
  report,
  loading,
  error,
  idle,
  children,
}: {
  report: T | null
  loading: boolean
  error: string | null
  idle?: string
  children: (report: T) => React.ReactNode
}) {
  if (error) {
    return (
      <p role="alert" className="text-sm text-destructive">
        {error}
      </p>
    )
  }
  if (loading) return <Skeleton className="h-48 w-full" />
  if (!report) return idle ? <p className="text-sm text-muted-foreground">{idle}</p> : null
  return <>{children(report)}</>
}

export function SectionTitle({ id, children }: { id: string; children: React.ReactNode }) {
  return (
    <h2 id={id} className="text-sm font-medium">
      {children}
    </h2>
  )
}

// ---- Tabla de reporte ----

export type ReportColumn<T> = {
  header: string
  cell: (row: T) => React.ReactNode
  /** Columnas numéricas: alineadas a la derecha. */
  numeric?: boolean
}

/**
 * Tabla de un reporte (sin paginar: el reporte trae todas las filas, igual que
 * el Excel/PDF). `title` la nombra para lectores de pantalla; `footer` son las
 * celdas del renglón "Total" después del encabezado de fila.
 */
export function ReportTable<T>({
  id,
  title,
  columns,
  rows,
  rowKey,
  empty,
  footer,
}: {
  id: string
  title: string
  columns: ReportColumn<T>[]
  rows: T[]
  rowKey: (row: T, index: number) => string
  empty: string
  footer?: React.ReactNode
}) {
  return (
    <section aria-labelledby={`${id}-title`} className="flex flex-col gap-2">
      <SectionTitle id={`${id}-title`}>{title}</SectionTitle>
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">{empty}</p>
      ) : (
        <div className="rounded-lg border">
          <Table aria-labelledby={`${id}-title`}>
            <TableHeader>
              <TableRow>
                {columns.map((column) => (
                  <TableHead key={column.header} scope="col" className={cn(column.numeric && 'text-right')}>
                    {column.header}
                  </TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row, index) => (
                <TableRow key={rowKey(row, index)}>
                  {columns.map((column) => (
                    <TableCell key={column.header} className={cn(column.numeric && numeric)}>
                      {column.cell(row)}
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
            {footer && (
              <TableFooter>
                <TableRow>
                  <TableHead scope="row">Total</TableHead>
                  {footer}
                </TableRow>
              </TableFooter>
            )}
          </Table>
        </div>
      )}
    </section>
  )
}

export const muted = <span className="text-muted-foreground">—</span>

// ---- Búsqueda de lo que se reporta (empleado, activo, tipo) ----

const SEARCH_DEBOUNCE_MS = 300

/**
 * Buscador con resultados y botón "Ver reporte" por resultado. Las opciones
 * vienen de endpoints de Reportes (`reportes.ver`), que incluyen inactivos /
 * dados de baja: el reporte sirve también para el historial.
 */
export function OptionSearch<T>({
  label,
  placeholder,
  search,
  itemKey,
  itemName,
  itemDetail,
  inactive,
  onSelect,
  empty,
  failed,
}: {
  label: string
  placeholder: string
  search: (term: string) => Promise<{ data: T[] }>
  itemKey: (item: T) => string
  itemName: (item: T) => string
  itemDetail: (item: T) => string
  inactive?: (item: T) => boolean
  onSelect: (item: T) => void
  empty: string
  failed: string
}) {
  const [searchInput, setSearchInput] = useState('')
  const [results, setResults] = useState<{ term: string; items: T[] | null } | null>(null)

  useEffect(() => {
    const term = searchInput.trim()
    if (term === '') return
    let cancelled = false
    const timeout = setTimeout(() => {
      search(term)
        .then(({ data }) => {
          if (!cancelled) setResults({ term, items: data })
        })
        .catch(() => {
          if (!cancelled) setResults({ term, items: null })
        })
    }, SEARCH_DEBOUNCE_MS)
    return () => {
      cancelled = true
      clearTimeout(timeout)
    }
    // `search` es una función estable del módulo de API.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchInput])

  const term = searchInput.trim()
  const current = results && results.term === term ? results : null

  return (
    <div className="flex flex-col gap-2">
      <div className="relative sm:w-72">
        <SearchIcon className="pointer-events-none absolute top-2 left-2.5 size-4 text-muted-foreground" aria-hidden />
        <Input
          type="search"
          aria-label={label}
          placeholder={placeholder}
          value={searchInput}
          onChange={(event) => setSearchInput(event.target.value)}
          className="pl-8"
        />
      </div>
      {term === '' ? null : !current ? (
        <p className="text-xs text-muted-foreground">Buscando…</p>
      ) : current.items === null ? (
        <p role="alert" className="text-xs text-destructive">
          {failed}
        </p>
      ) : current.items.length === 0 ? (
        <p className="text-xs text-muted-foreground">{empty}</p>
      ) : (
        <ul className="flex flex-col gap-1 sm:max-w-xl" aria-label={`Resultados: ${label}`}>
          {current.items.map((item) => (
            <li key={itemKey(item)} className="flex items-center justify-between gap-3 rounded-lg border px-2.5 py-1.5 text-sm">
              <div className="min-w-0">
                <p className="font-medium">{inactive?.(item) ? `${itemName(item)} (inactivo)` : itemName(item)}</p>
                <p className="truncate text-xs text-muted-foreground">{itemDetail(item)}</p>
              </div>
              <Button
                type="button"
                size="sm"
                variant="outline"
                aria-label={`Ver reporte de ${itemName(item)}`}
                onClick={() => {
                  onSelect(item)
                  setSearchInput('')
                }}
              >
                Ver reporte
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
