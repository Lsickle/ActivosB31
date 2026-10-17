import { apiFetch } from '../../lib/api-client'
import { jsonBody, query, type Paginated } from '../../lib/api-helpers'
import type { AssetStatus } from '../activos/schemas'
import type { CountLineValues, CountStatus, MovementType, MovementValues, NewCountValues } from './schemas'

// Contrato: specs/006-modulo-inventario/plan.md ("Contrato (resumen)").
// Ver = inventario.ver; registrar movimientos y conteos = inventario.gestionar
// (FR-006). Los movimientos no se editan ni se borran (D-002): un error se
// corrige con un movimiento inverso.

type NamedRef = { id: number; name: string }

// Fila tipo × ubicación de stock_levels (solo tipos por cantidad, RN-007).
export type StockRow = {
  asset_type: { id: number; name: string; category: NamedRef }
  location: { id: number; name: string; is_active: boolean }
  quantity: number
  /** Existencia total del tipo en todas las ubicaciones. */
  type_total: number
  /** RN-028: null si el tipo no tiene stock mínimo. */
  min_stock: number | null
  /** RN-028: type_total < min_stock. */
  below_min: boolean
}

// D-005: activos serializados agrupados por tipo, ubicación y estado.
export type SerializedSummaryRow = {
  asset_type: NamedRef
  location: NamedRef | null
  status: AssetStatus
  count: number
}

export type MovementRecord = {
  id: number
  type: MovementType
  type_label: string
  asset_type: NamedRef
  quantity: number
  from_location: NamedRef | null
  to_location: NamedRef | null
  reason: string | null
  /** Generado por una entrega o devolución (007). */
  delivery_id: number | null
  /** Ajuste generado al cerrar un conteo (RN-027). */
  count_id: number | null
  user: NamedRef | null
  created_at: string
}

export type InventoryCountLine = {
  id: number
  /** Renglón de cantidad (o tipo del activo, si el backend lo incluye). */
  asset_type: NamedRef | null
  /** Renglón de un activo serializado. */
  asset: { id: number; plate: string } | null
  /** Cantidad esperada (renglones de cantidad); al cerrar se recalcula (D-004). */
  expected_quantity: number | null
  counted_quantity: number | null
  /** Serializados: null = sin revisar. */
  found: boolean | null
}

export type InventoryCount = {
  id: number
  location: NamedRef
  status: CountStatus
  notes: string | null
  opened_by: NamedRef | null
  closed_by: NamedRef | null
  opened_at: string
  closed_at: string | null
  lines: InventoryCountLine[]
  /** Solo en la respuesta del cierre: hubo movimientos desde la apertura (edge case 4). */
  changed_since_open?: boolean
}

// En el listado no hacen falta los renglones.
export type InventoryCountSummary = Omit<InventoryCount, 'lines'> & { lines?: InventoryCountLine[] }

// RN-028: tipos por cantidad con existencia total bajo el mínimo. No está
// detallado en plan.md: forma propuesta para el backend.
export type StockAlert = {
  asset_type: { id: number; name: string; category: NamedRef }
  type_total: number
  min_stock: number
}

export type StockParams = {
  assetCategoryId?: number
  assetTypeId?: number
  locationId?: number
  belowMin?: boolean
  page?: number
  perPage?: number
}

export type MovementListParams = {
  type?: MovementType
  assetTypeId?: number
  locationId?: number
  /** YYYY-MM-DD */
  from?: string
  /** YYYY-MM-DD */
  to?: string
  page?: number
  perPage?: number
}

export type CountListParams = {
  status?: CountStatus
  locationId?: number
  page?: number
  perPage?: number
}

export const inventoryApi = {
  stock: (params: StockParams = {}): Promise<Paginated<StockRow>> =>
    apiFetch(
      `/api/inventory/stock${query({
        asset_category_id: params.assetCategoryId,
        asset_type_id: params.assetTypeId,
        location_id: params.locationId,
        // "Solo bajo mínimo" apagado = sin filtro (no below_min=0).
        below_min: params.belowMin ? true : undefined,
        page: params.page,
        per_page: params.perPage,
      })}`
    ),
  serializedSummary: (): Promise<{ data: SerializedSummaryRow[] }> => apiFetch('/api/inventory/serialized-summary'),
  movements: {
    list: (params: MovementListParams = {}): Promise<Paginated<MovementRecord>> =>
      apiFetch(
        `/api/inventory/movements${query({
          type: params.type,
          asset_type_id: params.assetTypeId,
          location_id: params.locationId,
          from: params.from,
          to: params.to,
          page: params.page,
          per_page: params.perPage,
        })}`
      ),
    create: (values: MovementValues): Promise<{ data: MovementRecord }> =>
      apiFetch('/api/inventory/movements', jsonBody('POST', values)),
  },
  counts: {
    list: (params: CountListParams = {}): Promise<Paginated<InventoryCountSummary>> =>
      apiFetch(
        `/api/inventory/counts${query({
          status: params.status,
          location_id: params.locationId,
          page: params.page,
          per_page: params.perPage,
        })}`
      ),
    get: (id: number): Promise<{ data: InventoryCount }> => apiFetch(`/api/inventory/counts/${id}`),
    create: (values: NewCountValues): Promise<{ data: InventoryCount }> => apiFetch('/api/inventory/counts', jsonBody('POST', values)),
    updateLines: (id: number, lines: CountLineValues[]): Promise<{ data: InventoryCount }> =>
      apiFetch(`/api/inventory/counts/${id}/lines`, jsonBody('PATCH', { lines })),
    close: (id: number): Promise<{ data: InventoryCount }> => apiFetch(`/api/inventory/counts/${id}/close`, { method: 'POST' }),
  },
  alerts: (): Promise<{ data: StockAlert[] }> => apiFetch('/api/inventory/alerts'),
}
