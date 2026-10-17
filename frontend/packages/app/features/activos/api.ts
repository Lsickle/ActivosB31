import { apiFetch } from '../../lib/api-client'
import { jsonBody, query, type Paginated } from '../../lib/api-helpers'
import type { TrackingMode } from '../configuracion/schemas'
import type {
  AssetFormValues,
  AssetStatus,
  AssetUpdateValues,
  ExpirySettingsValues,
  MaintenancePlanValues,
  MaintenanceRecordValues,
  StatusChangeValues,
} from './schemas'

// Contrato: specs/005-modulo-activos/plan.md ("Contrato (resumen)").
// Ver = activos.ver; crear/editar/estado/ubicación/foto/mantenimientos =
// activos.gestionar (FR-007). No hay eliminación de activos (FR-001).

// AssetResource (plan.md). Los decimales (numeric(14,2)) pueden llegar como
// texto ("1250000.50") por el cast `decimal` de Laravel.
export type AssetRecord = {
  id: number
  plate: string
  serial: string | null
  brand: string | null
  model: string | null
  /** YYYY-MM-DD */
  acquisition_date: string | null
  acquisition_value: string | number | null
  useful_life_months: number | null
  /** YYYY-MM-DD, RN-031 */
  expires_on: string | null
  status: AssetStatus
  status_label: string
  notes: string | null
  /** URL temporal (FR-008); null si no tiene foto. */
  photo_url: string | null
  asset_type: { id: number; name: string; tracking_mode: TrackingMode; category: { id: number; name: string } }
  location: { id: number; name: string; is_active: boolean } | null
  /** RN-039: quién lo tiene (lo fija Entregas). */
  holder: { id: number; full_name: string; position: string } | null
  /** Próximo mantenimiento de sus planes activos (D-003). */
  next_maintenance_on: string | null
  created_at: string
  updated_at: string
}

export type AssetListParams = {
  search?: string
  assetCategoryId?: number
  assetTypeId?: number
  status?: AssetStatus
  locationId?: number
  holderEmployeeId?: number
  page?: number
  perPage?: number
}

// D-002: eventos del historial (solo inserción).
export const ASSET_EVENT_TYPES = [
  'created',
  'updated',
  'status_changed',
  'location_changed',
  'delivered',
  'returned',
  'maintenance_done',
  'maintenance_plan_created',
  'maintenance_plan_updated',
  'retired',
] as const

export type AssetEventType = (typeof ASSET_EVENT_TYPES)[number]

type NamedRef = { id: number; name: string }

// No está detallado en plan.md: forma propuesta para el backend (columnas de
// asset_events con las referencias resueltas).
export type AssetEventRecord = {
  id: number
  event: AssetEventType
  from_status: AssetStatus | null
  to_status: AssetStatus | null
  from_location: NamedRef | null
  to_location: NamedRef | null
  employee: { id: number; full_name: string } | null
  user: { id: number; name: string } | null
  /** Motivo de la baja, detalle del mantenimiento u observación. */
  notes: string | null
  /** D-002 `updated`: campos cambiados. */
  changes?: Record<string, { from: unknown; to: unknown }> | null
  created_at: string
}

export type MaintenancePlanRecord = {
  id: number
  asset_id: number
  name: string
  interval_months: number
  last_done_on: string | null
  /** D-003: calculado por el servidor. */
  next_due_on: string
  alert_days: number
  is_active: boolean
  created_at: string
  updated_at: string
}

export type MaintenanceRecordRecord = {
  id: number
  plan: NamedRef | null
  performed_on: string
  description: string
  performed_by: string | null
  cost: string | number | null
  user: { id: number; name: string } | null
  created_at: string
}

// D-004: alertas de planes activos y vencimientos de activos no dados de baja.
type AlertAsset = { id: number; plate: string; asset_type: { id: number; name: string } }

export type MaintenanceAlert = {
  plan_id: number
  plan_name: string
  next_due_on: string
  overdue: boolean
  asset: AlertAsset
}

export type ExpirationAlert = {
  expires_on: string
  overdue: boolean
  asset: AlertAsset
}

export type AssetAlerts = { maintenance: MaintenanceAlert[]; expirations: ExpirationAlert[] }

export type AppSettings = { expiry_alert_days: number }

type PageParams = { page?: number; perPage?: number }

function pageQuery(params: PageParams = {}): string {
  return query({ page: params.page, per_page: params.perPage })
}

// Multipart: los null viajan vacíos (ConvertEmptyStringsToNull de Laravel los vuelve null).
function assetFormData(values: AssetFormValues, photo?: Blob | null): FormData {
  const body = new FormData()
  for (const [key, value] of Object.entries(values)) {
    body.append(key, value === null || value === undefined ? '' : String(value))
  }
  if (photo) body.append('photo', photo)
  return body
}

function photoFormData(photo: Blob): FormData {
  const body = new FormData()
  body.append('photo', photo)
  return body
}

export const assetsApi = {
  list: (params: AssetListParams = {}): Promise<Paginated<AssetRecord>> =>
    apiFetch(
      `/api/assets${query({
        search: params.search?.trim(),
        asset_category_id: params.assetCategoryId,
        asset_type_id: params.assetTypeId,
        status: params.status,
        location_id: params.locationId,
        holder_employee_id: params.holderEmployeeId,
        page: params.page,
        per_page: params.perPage,
      })}`
    ),
  get: (id: number): Promise<{ data: AssetRecord }> => apiFetch(`/api/assets/${id}`),
  create: (values: AssetFormValues, photo?: Blob | null): Promise<{ data: AssetRecord }> =>
    apiFetch('/api/assets', { method: 'POST', body: assetFormData(values, photo) }),
  update: (id: number, values: AssetUpdateValues): Promise<{ data: AssetRecord }> =>
    apiFetch(`/api/assets/${id}`, jsonBody('PATCH', values)),
  uploadPhoto: (id: number, photo: Blob): Promise<{ data: AssetRecord }> =>
    apiFetch(`/api/assets/${id}/photo`, { method: 'POST', body: photoFormData(photo) }),
  deletePhoto: (id: number): Promise<unknown> => apiFetch(`/api/assets/${id}/photo`, { method: 'DELETE' }),
  updateStatus: (id: number, values: StatusChangeValues): Promise<{ data: AssetRecord }> =>
    apiFetch(`/api/assets/${id}/status`, jsonBody('PATCH', values)),
  updateLocation: (id: number, locationId: number): Promise<{ data: AssetRecord }> =>
    apiFetch(`/api/assets/${id}/location`, jsonBody('PATCH', { location_id: locationId })),
  listEvents: (id: number, params?: PageParams): Promise<Paginated<AssetEventRecord>> =>
    apiFetch(`/api/assets/${id}/events${pageQuery(params)}`),
  maintenancePlans: {
    list: (assetId: number): Promise<{ data: MaintenancePlanRecord[] }> => apiFetch(`/api/assets/${assetId}/maintenance-plans`),
    create: (assetId: number, values: MaintenancePlanValues): Promise<{ data: MaintenancePlanRecord }> =>
      apiFetch(`/api/assets/${assetId}/maintenance-plans`, jsonBody('POST', values)),
    update: (assetId: number, planId: number, values: MaintenancePlanValues): Promise<{ data: MaintenancePlanRecord }> =>
      apiFetch(`/api/assets/${assetId}/maintenance-plans/${planId}`, jsonBody('PATCH', values)),
  },
  // SC-003: los registros realizados no se editan ni se borran.
  maintenanceRecords: {
    list: (assetId: number, params?: PageParams): Promise<Paginated<MaintenanceRecordRecord>> =>
      apiFetch(`/api/assets/${assetId}/maintenance-records${pageQuery(params)}`),
    create: (assetId: number, values: MaintenanceRecordValues): Promise<{ data: MaintenanceRecordRecord }> =>
      apiFetch(`/api/assets/${assetId}/maintenance-records`, jsonBody('POST', values)),
  },
  alerts: (): Promise<{ data: AssetAlerts }> => apiFetch('/api/assets/alerts'),
}

// D-005: ajustes globales. Ver = configuracion.ver; cambiar = configuracion.gestionar.
export const settingsApi = {
  get: (): Promise<{ data: AppSettings }> => apiFetch('/api/settings'),
  update: (values: ExpirySettingsValues): Promise<{ data: AppSettings }> => apiFetch('/api/settings', jsonBody('PUT', values)),
}
