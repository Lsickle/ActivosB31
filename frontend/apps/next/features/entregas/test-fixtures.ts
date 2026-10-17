import type { DeliveryItemRecord, DeliveryRecord, EmployeeHolding } from 'app/features/entregas/api'

// Datos de prueba de la feature 007 (solo tests).

export function deliveryItem(overrides: Partial<DeliveryItemRecord> = {}): DeliveryItemRecord {
  return {
    id: 1,
    asset: { id: 7, plate: 'B31-0001' },
    asset_type: { id: 3, name: 'Cilindro SCBA', tracking_mode: 'serialized' },
    quantity: 1,
    returned_quantity: 0,
    pending_quantity: 1,
    returns: [],
    ...overrides,
  }
}

export const glovesItem = deliveryItem({
  id: 2,
  asset: null,
  asset_type: { id: 5, name: 'Guantes de rescate', tracking_mode: 'quantity' },
  quantity: 2,
  returned_quantity: 0,
  pending_quantity: 2,
})

export function delivery(overrides: Partial<DeliveryRecord> = {}): DeliveryRecord {
  return {
    id: 1,
    code: 'ENT-000001',
    employee: { id: 10, full_name: 'Ana Pérez', position: 'Bombero' },
    kind: 'dotacion',
    kind_label: 'Dotación',
    due_on: null,
    status: 'open',
    from_location: { id: 4, name: 'Bodega principal' },
    notes: null,
    delivered_by: { id: 1, name: 'Administrador' },
    delivered_at: '2026-10-17T15:00:00Z',
    items: [deliveryItem(), glovesItem],
    ...overrides,
  }
}

export function holding(overrides: Partial<EmployeeHolding> = {}): EmployeeHolding {
  return {
    delivery_item_id: 1,
    delivery: {
      id: 1,
      code: 'ENT-000001',
      kind: 'dotacion',
      kind_label: 'Dotación',
      due_on: null,
      status: 'open',
      delivered_at: '2026-10-17T15:00:00Z',
    },
    asset: { id: 7, plate: 'B31-0001' },
    asset_type: { id: 3, name: 'Cilindro SCBA', tracking_mode: 'serialized' },
    quantity: 1,
    pending_quantity: 1,
    ...overrides,
  }
}
