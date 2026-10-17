import type { ConfigurationOptions } from 'app/features/configuracion/api'
import type { InventoryCount, MovementRecord, StockRow } from 'app/features/inventario/api'

// Datos de prueba de la feature 006 (solo tests).

// Opciones disponibles (solo activos, 002 FR-010). "Guantes de rescate" y
// "Cinta de señalización" son por cantidad; "Cilindro SCBA" es serializado y
// no debe ofrecerse en los movimientos (RN-007).
export const options: ConfigurationOptions = {
  asset_categories: [
    { id: 1, name: 'EPP' },
    { id: 2, name: 'Señalización' },
  ],
  asset_types: [
    { id: 3, name: 'Cilindro SCBA', asset_category_id: 1, tracking_mode: 'serialized' },
    { id: 5, name: 'Guantes de rescate', asset_category_id: 1, tracking_mode: 'quantity' },
    { id: 6, name: 'Cinta de señalización', asset_category_id: 2, tracking_mode: 'quantity' },
  ],
  location_types: [],
  locations: [
    { id: 4, name: 'Bodega principal', location_type_id: 1 },
    { id: 8, name: 'Máquina 1', location_type_id: 2 },
  ],
  positions: [],
  companies: [],
}

export function stockRow(overrides: Partial<StockRow> = {}): StockRow {
  return {
    asset_type: { id: 5, name: 'Guantes de rescate', category: { id: 1, name: 'EPP' } },
    location: { id: 4, name: 'Bodega principal', is_active: true },
    quantity: 15,
    type_total: 20,
    min_stock: 10,
    below_min: false,
    ...overrides,
  }
}

export function movement(overrides: Partial<MovementRecord> = {}): MovementRecord {
  return {
    id: 1,
    type: 'entry',
    type_label: 'Entrada',
    asset_type: { id: 5, name: 'Guantes de rescate' },
    quantity: 20,
    from_location: null,
    to_location: { id: 4, name: 'Bodega principal' },
    reason: 'Compra',
    delivery_id: null,
    count_id: null,
    user: { id: 1, name: 'Almacenista' },
    created_at: '2026-10-17T15:30:00Z',
    ...overrides,
  }
}

export function count(overrides: Partial<InventoryCount> = {}): InventoryCount {
  return {
    id: 12,
    location: { id: 4, name: 'Bodega principal' },
    status: 'open',
    notes: null,
    opened_by: { id: 1, name: 'Almacenista' },
    closed_by: null,
    opened_at: '2026-10-17T14:00:00Z',
    closed_at: null,
    lines: [
      {
        id: 101,
        asset_type: { id: 5, name: 'Guantes de rescate' },
        asset: null,
        expected_quantity: 15,
        counted_quantity: null,
        found: null,
      },
      {
        id: 102,
        asset_type: { id: 3, name: 'Cilindro SCBA' },
        asset: { id: 7, plate: 'B31-0001' },
        expected_quantity: null,
        counted_quantity: null,
        found: null,
      },
    ],
    ...overrides,
  }
}
