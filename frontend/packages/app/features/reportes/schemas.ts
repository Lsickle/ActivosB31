import { z } from 'zod'

// Reglas de specs/008-modulo-reportes (FR-001, FR-002; RN-032, RN-033).

// ---- Reportes y formatos (RN-032, D-002) ----

export const REPORT_KINDS = ['inventory', 'employee', 'asset', 'asset-type'] as const

export type ReportKind = (typeof REPORT_KINDS)[number]

/** Formatos de descarga; la pantalla usa `json`. */
export const REPORT_FORMATS = ['xlsx', 'pdf'] as const

export type ReportFormat = (typeof REPORT_FORMATS)[number]

export const REPORT_FORMAT_LABELS: Record<ReportFormat, string> = {
  xlsx: 'Excel',
  pdf: 'PDF',
}

const FILE_PREFIX: Record<ReportKind, string> = {
  inventory: 'inventario',
  employee: 'reporte-empleado',
  asset: 'reporte-activo',
  'asset-type': 'reporte-tipo',
}

// Nombre de respaldo si el servidor no envía Content-Disposition (o CORS no lo expone).
export function reportFileName(kind: ReportKind, format: ReportFormat, today: string): string {
  return `${FILE_PREFIX[kind]}-${today}.${format}`
}

// ---- Rango de fechas (RN-033) ----

const isoDate = z.iso.date()

/**
 * RN-033: Desde/Hasta (YYYY-MM-DD) opcionales e inclusivos. Devuelve el mensaje
 * de error o null. El backend valida igual (`from`/`to`, `to >= from`).
 */
export function dateRangeError(from: string, to: string): string | null {
  if (from !== '' && !isoDate.safeParse(from).success) return 'La fecha "Desde" no es válida.'
  if (to !== '' && !isoDate.safeParse(to).success) return 'La fecha "Hasta" no es válida.'
  if (from !== '' && to !== '' && from > to) return '"Desde" no puede ser posterior a "Hasta".'
  return null
}
