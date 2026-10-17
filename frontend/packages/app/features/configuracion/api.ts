import { apiFetch } from '../../lib/api-client'
import { jsonBody, query, type Paginated, type QueryValue } from '../../lib/api-helpers'
import type {
  AssetCategoryFormValues,
  AssetTypeFormValues,
  CatalogFormValues,
  LocationFormValues,
  LocationTypeFormValues,
  TrackingMode,
} from './schemas'

// Contrato: specs/002-modulo-configuracion/contracts/api.md
// Ver = configuracion.ver; crear/editar/estado = configuracion.gestionar (RN-011).
// No hay eliminación: solo activar/desactivar (RN-003).

type Timestamps = { created_at: string; updated_at: string }

export type AssetCategoryRecord = Timestamps & {
  id: number
  name: string
  description: string | null
  is_active: boolean
  asset_types_count: number
}

export type AssetTypeRecord = Timestamps & {
  id: number
  name: string
  description: string | null
  tracking_mode: TrackingMode
  tracking_mode_label: string
  /** 005 D-008 / RN-028: solo en tipos por cantidad. */
  min_stock: number | null
  is_active: boolean
  category: { id: number; name: string; is_active: boolean }
}

export type LocationTypeRecord = Timestamps & {
  id: number
  name: string
  description: string | null
  is_active: boolean
  locations_count: number
}

export type LocationRecord = Timestamps & {
  id: number
  name: string
  description: string | null
  is_active: boolean
  location_type: { id: number; name: string; is_active: boolean }
}

// specs/004-modulo-empleados D-001: cargos o rangos (RN-035) y compañías o
// turnos (RN-036), catálogos de Configuración con el mismo patrón de 002.
export type PositionRecord = Timestamps & {
  id: number
  name: string
  description: string | null
  is_active: boolean
}

export type CompanyRecord = Timestamps & {
  id: number
  name: string
  description: string | null
  is_active: boolean
}

export type CatalogListParams = {
  search?: string
  isActive?: boolean
  page?: number
  perPage?: number
}

export type AssetTypeListParams = CatalogListParams & {
  assetCategoryId?: number
  trackingMode?: TrackingMode
}

export type LocationListParams = CatalogListParams & {
  locationTypeId?: number
}

// Operaciones comunes de los cuatro catálogos (patrón de contracts/api.md).
export type CatalogApi<TRecord, TValues, TParams extends CatalogListParams = CatalogListParams> = {
  list: (params?: TParams) => Promise<Paginated<TRecord>>
  create: (values: TValues) => Promise<{ data: TRecord }>
  update: (id: number, values: TValues) => Promise<{ data: TRecord }>
  updateStatus: (id: number, isActive: boolean) => Promise<{ data: TRecord }>
}

function catalogApi<TRecord, TValues, TParams extends CatalogListParams = CatalogListParams>(
  path: string,
  extraFilters: (params: TParams) => Record<string, QueryValue> = () => ({})
): CatalogApi<TRecord, TValues, TParams> {
  return {
    list: (params = {} as TParams) =>
      apiFetch(
        `${path}${query({
          search: params.search?.trim(),
          is_active: params.isActive,
          ...extraFilters(params),
          page: params.page,
          per_page: params.perPage,
        })}`
      ),
    create: (values) => apiFetch(path, jsonBody('POST', values)),
    update: (id, values) => apiFetch(`${path}/${id}`, jsonBody('PATCH', values)),
    updateStatus: (id, isActive) => apiFetch(`${path}/${id}/status`, jsonBody('PATCH', { is_active: isActive })),
  }
}

export const assetCategoriesApi = catalogApi<AssetCategoryRecord, AssetCategoryFormValues>('/api/asset-categories')

export const assetTypesApi = catalogApi<AssetTypeRecord, AssetTypeFormValues, AssetTypeListParams>(
  '/api/asset-types',
  (params) => ({ asset_category_id: params.assetCategoryId, tracking_mode: params.trackingMode })
)

export const locationTypesApi = catalogApi<LocationTypeRecord, LocationTypeFormValues>('/api/location-types')

export const locationsApi = catalogApi<LocationRecord, LocationFormValues, LocationListParams>('/api/locations', (params) => ({
  location_type_id: params.locationTypeId,
}))

export const positionsApi = catalogApi<PositionRecord, CatalogFormValues>('/api/positions')

export const companiesApi = catalogApi<CompanyRecord, CatalogFormValues>('/api/companies')

// ---- Opciones disponibles (FR-010, D-005) ----
// Solo registros activos; tipos de activo de categorías activas y ubicaciones
// de tipos activos. Sin paginar, ordenados por nombre.

export type ConfigurationOptions = {
  asset_categories: { id: number; name: string }[]
  asset_types: { id: number; name: string; asset_category_id: number; tracking_mode: TrackingMode }[]
  location_types: { id: number; name: string }[]
  locations: { id: number; name: string; location_type_id: number }[]
  /** 004 D-001: cargos y compañías activos, para el formulario de empleados. */
  positions: { id: number; name: string }[]
  companies: { id: number; name: string }[]
}

export function getConfigurationOptions(): Promise<{ data: ConfigurationOptions }> {
  return apiFetch('/api/configuracion/options')
}
