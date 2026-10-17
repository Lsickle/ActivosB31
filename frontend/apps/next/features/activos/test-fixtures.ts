import { act, fireEvent, screen } from '@testing-library/react'
import type { AssetRecord, MaintenancePlanRecord } from 'app/features/activos/api'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import type { Paginated } from 'app/lib/api-helpers'

// Datos de prueba de la feature 005 (solo tests).

export function asset(overrides: Partial<AssetRecord> = {}): AssetRecord {
  return {
    id: 7,
    plate: 'B31-0001',
    serial: 'SN-123',
    brand: 'MSA',
    model: 'G1',
    acquisition_date: '2024-02-10',
    acquisition_value: '1250000.00',
    useful_life_months: 120,
    expires_on: null,
    status: 'available',
    status_label: 'Disponible',
    notes: null,
    photo_url: null,
    asset_type: { id: 3, name: 'Cilindro SCBA', tracking_mode: 'serialized', category: { id: 1, name: 'EPP' } },
    location: { id: 4, name: 'Bodega principal', is_active: true },
    holder: null,
    next_maintenance_on: null,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

export function plan(overrides: Partial<MaintenancePlanRecord> = {}): MaintenancePlanRecord {
  return {
    id: 3,
    asset_id: 7,
    name: 'Prueba hidrostática',
    interval_months: 60,
    last_done_on: '2022-03-10',
    next_due_on: '2027-03-10',
    alert_days: 30,
    is_active: true,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

// Opciones disponibles: solo activos (002 FR-010). "Guantes de rescate" es por
// cantidad (RN-007) y no debe ofrecerse en Activos.
export const options: ConfigurationOptions = {
  asset_categories: [
    { id: 1, name: 'EPP' },
    { id: 2, name: 'Comunicaciones' },
  ],
  asset_types: [
    { id: 3, name: 'Cilindro SCBA', asset_category_id: 1, tracking_mode: 'serialized' },
    { id: 5, name: 'Guantes de rescate', asset_category_id: 1, tracking_mode: 'quantity' },
    { id: 6, name: 'Radio portátil', asset_category_id: 2, tracking_mode: 'serialized' },
  ],
  location_types: [],
  locations: [
    { id: 4, name: 'Bodega principal', location_type_id: 1 },
    { id: 8, name: 'Máquina 1', location_type_id: 2 },
  ],
  positions: [],
  companies: [],
}

export function page<T>(data: T[], overrides: Partial<Paginated<T>['meta']> = {}): Paginated<T> {
  return {
    data,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length, ...overrides },
  }
}

export async function chooseOption(combobox: HTMLElement, name: string | RegExp) {
  fireEvent.click(combobox)
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}
