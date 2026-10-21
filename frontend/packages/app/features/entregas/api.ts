import { apiDownload, apiFetch, type DownloadedFile } from '../../lib/api-client'
import { jsonBody, query, type Paginated } from '../../lib/api-helpers'
import type { TrackingMode } from '../configuracion/schemas'
import type { AssetStatus } from '../activos/schemas'
import type { DeliveryKind, DeliveryPayload, DeliveryStatus, ReturnCondition, ReturnPayload } from './schemas'

// Contrato: specs/007-modulo-entregas/plan.md ("Contrato (resumen)").
// Ver = entregas.ver; registrar entregas y devoluciones = entregas.gestionar
// (FR-006). Las entregas son inmutables: no hay edición ni borrado (FR-001).

type NamedRef = { id: number; name: string }

/**
 * B-2 (revisión de seguridad de 007): recurso reducido de GET /api/assets
 * que recibe quien tiene entregas.gestionar sin activos.ver (solo
 * Disponibles). Con activos.ver llega el AssetRecord completo, que es
 * compatible con este tipo: el asistente de entrega solo usa estos campos.
 */
export type AssetForDelivery = {
  id: number
  plate: string
  serial: string | null
  status: AssetStatus
  asset_type: NamedRef
  location: NamedRef | null
}

// D-006 / RN-022: en Entregas el empleado viaja sin datos personales.
export type DeliveryEmployee = { id: number; full_name: string; position: string }

export type DeliveryReturnRecord = {
  id: number
  quantity: number
  condition: ReturnCondition
  condition_label: string
  /** null en lo perdido y en lo dañado por cantidad (RN-038). */
  to_location: NamedRef | null
  notes: string | null
  received_by: NamedRef
  returned_at: string
}

export type DeliveryItemRecord = {
  id: number
  /** Activo serializado; null en artículos por cantidad. */
  asset: { id: number; plate: string } | null
  asset_type: { id: number; name: string; tracking_mode: TrackingMode }
  quantity: number
  returned_quantity: number
  pending_quantity: number
  returns: DeliveryReturnRecord[]
}

export type DeliveryRecord = {
  id: number
  /** Consecutivo ENT-000001 (D-001). */
  code: string
  employee: DeliveryEmployee
  kind: DeliveryKind
  kind_label: string
  /** YYYY-MM-DD; solo préstamos. */
  due_on: string | null
  /** Derivado (D-005). */
  status: DeliveryStatus
  from_location: NamedRef
  notes: string | null
  delivered_by: NamedRef
  delivered_at: string
  /** URL temporal de la firma (D-002); solo en la ficha (show). */
  signature_url?: string | null
  items: DeliveryItemRecord[]
}

// FR-005: préstamos vencidos con artículos pendientes. No está detallado en
// plan.md: forma propuesta para el backend.
export type OverdueDeliveryAlert = {
  id: number
  code: string
  employee: DeliveryEmployee
  /** YYYY-MM-DD */
  due_on: string
  /** Unidades aún sin devolver. */
  pending_quantity: number
}

// D-007: un ítem pendiente del empleado. No está detallado en plan.md: forma
// propuesta para el backend (una fila por delivery_item con pendiente > 0).
export type EmployeeHolding = {
  delivery_item_id: number
  delivery: { id: number; code: string; kind: DeliveryKind; kind_label: string; due_on: string | null; status: DeliveryStatus; delivered_at: string }
  asset: { id: number; plate: string } | null
  asset_type: { id: number; name: string; tracking_mode: TrackingMode }
  quantity: number
  pending_quantity: number
}

export type DeliveryListParams = {
  search?: string
  employeeId?: number
  kind?: DeliveryKind
  status?: DeliveryStatus
  /** YYYY-MM-DD */
  from?: string
  /** YYYY-MM-DD */
  to?: string
  page?: number
  perPage?: number
}

export const deliveriesApi = {
  // Paginado, más recientes primero (lo hace el servidor).
  list: (params: DeliveryListParams = {}): Promise<Paginated<DeliveryRecord>> =>
    apiFetch(
      `/api/deliveries${query({
        search: params.search?.trim(),
        employee_id: params.employeeId,
        kind: params.kind,
        status: params.status,
        from: params.from,
        to: params.to,
        page: params.page,
        per_page: params.perPage,
      })}`
    ),
  get: (id: number): Promise<{ data: DeliveryRecord }> => apiFetch(`/api/deliveries/${id}`),
  create: (payload: DeliveryPayload): Promise<{ data: DeliveryRecord }> => apiFetch('/api/deliveries', jsonBody('POST', payload)),
  createReturn: (id: number, payload: ReturnPayload): Promise<{ data: DeliveryRecord }> =>
    apiFetch(`/api/deliveries/${id}/returns`, jsonBody('POST', payload)),
  alerts: (): Promise<{ data: OverdueDeliveryAlert[] }> => apiFetch('/api/deliveries/alerts'),
  // Acta en PDF (attachment ENT-000123.pdf). Mismo permiso que ver la firma;
  // 409 si la firma no pasa la verificación de integridad (RN-041; firma RN-024).
  downloadPdf: (id: number): Promise<DownloadedFile> => apiDownload(`/api/deliveries/${id}/pdf`),
}

// D-007: requiere empleados.ver (vive en la ficha del empleado).
export function employeeHoldings(employeeId: number): Promise<{ data: EmployeeHolding[] }> {
  return apiFetch(`/api/employees/${employeeId}/holdings`)
}
