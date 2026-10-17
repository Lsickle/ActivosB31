import { apiDownload, apiFetch, type DownloadedFile } from '../../lib/api-client'
import { query } from '../../lib/api-helpers'
import type { AssetAlerts, AssetEventType } from '../activos/api'
import type { AssetStatus } from '../activos/schemas'
import type { TrackingMode } from '../configuracion/schemas'
import type { DocumentType } from '../empleados/schemas'
import type { OverdueDeliveryAlert } from '../entregas/api'
import type { DeliveryKind, DeliveryStatus, ReturnCondition } from '../entregas/schemas'
import type { StockAlert } from '../inventario/api'
import type { MovementType } from '../inventario/schemas'
import type { ReportFormat } from './schemas'

// Contrato: specs/008-modulo-reportes/contracts/api.md. Todo exige
// reportes.ver; los datos personales del empleado, además empleados.ver
// (RN-022, D-003). JSON, Excel y PDF salen de la misma consulta (D-001).

type NamedRef = { id: number; name: string }
type TypeRef = { id: number; name: string; category: NamedRef }

/** Encabezado común: lo mismo que imprime el Excel/PDF (fecha, usuario, filtros). */
export type ReportMeta = {
  /** ISO 8601 (el archivo lo imprime en America/Bogota). */
  generated_at: string
  generated_by: NamedRef
  /** Filtros aplicados en texto legible, en el orden en que se imprimen. */
  filters: { label: string; value: string }[]
}

// ---- Inventario general (US1) ----

export type InventoryReportFilters = {
  assetCategoryId?: number
  assetTypeId?: number
  locationId?: number
  /** Solo aplica a los serializados (estado del activo, RN-005). */
  status?: AssetStatus
}

export type InventorySerializedRow = {
  asset_type: TypeRef
  location: NamedRef | null
  status: AssetStatus
  status_label: string
  count: number
}

export type InventoryQuantityRow = {
  asset_type: TypeRef
  location: NamedRef & { is_active: boolean }
  quantity: number
  /** Total del tipo en todas las ubicaciones (RN-028). */
  type_total: number
  min_stock: number | null
  below_min: boolean
}

export type InventoryReport = ReportMeta & {
  serialized: InventorySerializedRow[]
  quantity: InventoryQuantityRow[]
  totals: {
    serialized: number
    by_status: Record<AssetStatus, number>
    quantity_units: number
    below_min_types: number
  }
}

// ---- Por empleado (US2) ----

export type DateRange = {
  /** YYYY-MM-DD, inclusivo (RN-033). */
  from?: string
  /** YYYY-MM-DD, inclusivo (RN-033). */
  to?: string
}

/**
 * Nombre y cargo siempre; documento y contacto solo si el usuario tiene
 * empleados.ver (RN-022): sin ese permiso las claves no vienen.
 */
export type EmployeeReportEmployee = {
  id: number
  full_name: string
  position: string
  is_active: boolean
  document_type?: DocumentType
  document_number?: string
  phone?: string | null
  email?: string | null
  company?: string | null
}

export type EmployeeReportHolding = {
  delivery: { id: number; code: string; kind: DeliveryKind; kind_label: string; due_on: string | null; status: DeliveryStatus }
  asset: { id: number; plate: string } | null
  asset_type: { id: number; name: string; tracking_mode: TrackingMode }
  pending_quantity: number
  delivered_at: string
}

export type EmployeeReportHistoryRow = {
  /** Fecha y hora del evento (ISO 8601). */
  date: string
  event: 'delivery' | 'return'
  event_label: string
  delivery: { id: number; code: string; kind: DeliveryKind; kind_label: string }
  asset: { id: number; plate: string } | null
  asset_type: { id: number; name: string }
  quantity: number
  /** Solo en devoluciones. */
  condition: ReturnCondition | null
  condition_label: string | null
}

export type EmployeeReport = ReportMeta & {
  employee: EmployeeReportEmployee
  /** Lo que tiene hoy (no depende del rango). */
  holdings: EmployeeReportHolding[]
  /** Entregas y devoluciones dentro del rango, más recientes primero. */
  history: EmployeeReportHistoryRow[]
  totals: { pending_units: number; delivered_units: number; returned_units: number }
}

// ---- Por artículo: activo serializado (US3) ----

export type AssetReportEvent = {
  id: number
  event: AssetEventType
  event_label: string
  /** Texto ya armado por el servidor (igual que en el Excel/PDF). */
  detail: string | null
  user: NamedRef | null
  created_at: string
}

export type AssetReport = ReportMeta & {
  asset: {
    id: number
    plate: string
    serial: string | null
    brand: string | null
    model: string | null
    status: AssetStatus
    status_label: string
    asset_type: TypeRef
    location: NamedRef | null
    /** RN-039: quién lo tiene (nombre y cargo, sin datos personales). */
    holder: { id: number; full_name: string; position: string } | null
    acquisition_date: string | null
    expires_on: string | null
    next_maintenance_on: string | null
  }
  /** Historial (asset_events: entregas, devoluciones, mantenimientos, cambios) dentro del rango, más recientes primero. */
  events: AssetReportEvent[]
  totals: { events: number }
}

export type EmployeeReportOption = { id: number; full_name: string; position: string; is_active: boolean }

export type AssetTypeReportOption = { id: number; name: string; is_active: boolean; category: NamedRef }

export type AssetOption ={ id: number; plate: string; status: AssetStatus; status_label: string; asset_type: NamedRef }

// ---- Por artículo: tipo por cantidad (US3) ----

export type AssetTypeReportFilters = DateRange & { locationId?: number }

export type AssetTypeReportMovement = {
  id: number
  type: MovementType
  type_label: string
  quantity: number
  from_location: NamedRef | null
  to_location: NamedRef | null
  reason: string | null
  delivery: { id: number; code: string } | null
  user: NamedRef | null
  created_at: string
}

export type AssetTypeReport = ReportMeta & {
  asset_type: TypeRef & { min_stock: number | null }
  /** Existencias actuales por ubicación (no dependen del rango). */
  stock: { location: NamedRef & { is_active: boolean }; quantity: number }[]
  /** Movimientos (inventory_movements) dentro del rango, más recientes primero. */
  movements: AssetTypeReportMovement[]
  totals: { stock_total: number; below_min: boolean; movements: number }
}

// ---- Petición (misma para pantalla y descarga: D-001, D-002) ----

export type ReportRequest =
  | { kind: 'inventory'; filters: InventoryReportFilters }
  | { kind: 'employee'; employeeId: number; range: DateRange }
  | { kind: 'asset'; assetId: number; range: DateRange }
  | { kind: 'asset-type'; assetTypeId: number; filters: AssetTypeReportFilters }

export function reportPath(request: ReportRequest, format: ReportFormat | 'json'): string {
  switch (request.kind) {
    case 'inventory': {
      const { filters } = request
      return `/api/reports/inventory${query({
        asset_category_id: filters.assetCategoryId,
        asset_type_id: filters.assetTypeId,
        location_id: filters.locationId,
        status: filters.status,
        format,
      })}`
    }
    case 'employee':
      return `/api/reports/employees/${request.employeeId}${query({ from: request.range.from, to: request.range.to, format })}`
    case 'asset':
      return `/api/reports/assets/${request.assetId}${query({ from: request.range.from, to: request.range.to, format })}`
    case 'asset-type': {
      const { filters } = request
      return `/api/reports/asset-types/${request.assetTypeId}${query({
        from: filters.from,
        to: filters.to,
        location_id: filters.locationId,
        format,
      })}`
    }
  }
}

export const reportsApi = {
  inventory: (filters: InventoryReportFilters): Promise<{ data: InventoryReport }> =>
    apiFetch(reportPath({ kind: 'inventory', filters }, 'json')),
  employee: (employeeId: number, range: DateRange): Promise<{ data: EmployeeReport }> =>
    apiFetch(reportPath({ kind: 'employee', employeeId, range }, 'json')),
  asset: (assetId: number, range: DateRange): Promise<{ data: AssetReport }> =>
    apiFetch(reportPath({ kind: 'asset', assetId, range }, 'json')),
  assetType: (assetTypeId: number, filters: AssetTypeReportFilters): Promise<{ data: AssetTypeReport }> =>
    apiFetch(reportPath({ kind: 'asset-type', assetTypeId, filters }, 'json')),
  /** Hasta 20 activos (todos los estados, incluso dados de baja) cuya placa o serial coincide. */
  assetOptions: (search: string): Promise<{ data: AssetOption[] }> =>
    apiFetch(`/api/reports/asset-options${query({ search: search.trim() })}`),
  /** Hasta 20 empleados, incluidos inactivos (historial de quien ya se retiró). Solo nombre y cargo (RN-022). */
  employeeOptions: (search: string): Promise<{ data: EmployeeReportOption[] }> =>
    apiFetch(`/api/reports/employee-options${query({ search: search.trim() })}`),
  /** Hasta 20 tipos por cantidad, incluidos inactivos. */
  assetTypeOptions: (search: string): Promise<{ data: AssetTypeReportOption[] }> =>
    apiFetch(`/api/reports/asset-type-options${query({ search: search.trim() })}`),
  /** D-002 / RN-032: el archivo con los mismos filtros que la pantalla. */
  download: (request: ReportRequest, format: ReportFormat): Promise<DownloadedFile> => apiDownload(reportPath(request, format)),
}

// ---- Tablero de Inicio (US4, D-004) ----

/** Cada sección viene solo si el usuario puede ver su módulo; si no, la clave no viene. */
export type Dashboard = {
  /** activos.ver */
  assets?: {
    by_status: Record<AssetStatus, number>
    total: number
    alerts: AssetAlerts
  }
  /** inventario.ver */
  inventory?: {
    below_min: StockAlert[]
  }
  /** entregas.ver */
  deliveries?: {
    /** Entregas con pendientes y sin vencer (status `open`). */
    open_count: number
    /** Préstamos vencidos con pendientes (status `overdue`). */
    overdue_count: number
    overdue: OverdueDeliveryAlert[]
  }
}

export const dashboardApi = {
  get: (): Promise<{ data: Dashboard }> => apiFetch('/api/dashboard'),
}
