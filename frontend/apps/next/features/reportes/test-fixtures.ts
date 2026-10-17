import type {
  AssetReport,
  AssetTypeReport,
  Dashboard,
  EmployeeReport,
  InventoryReport,
  ReportMeta,
} from 'app/features/reportes/api'

// Datos de prueba de la feature 008 (solo tests).

export const meta: ReportMeta = {
  generated_at: '2026-10-17T15:30:00Z',
  generated_by: { id: 1, name: 'Administrador' },
  filters: [{ label: 'Ubicación', value: 'Bodega principal' }],
}

export function inventoryReport(overrides: Partial<InventoryReport> = {}): InventoryReport {
  return {
    ...meta,
    serialized: [
      {
        asset_type: { id: 3, name: 'Cilindro SCBA', category: { id: 1, name: 'EPP' } },
        location: { id: 4, name: 'Bodega principal' },
        status: 'available',
        status_label: 'Disponible',
        count: 6,
      },
      {
        asset_type: { id: 3, name: 'Cilindro SCBA', category: { id: 1, name: 'EPP' } },
        location: null,
        status: 'assigned',
        status_label: 'Asignado',
        count: 2,
      },
    ],
    quantity: [
      {
        asset_type: { id: 5, name: 'Guantes de rescate', category: { id: 1, name: 'EPP' } },
        location: { id: 4, name: 'Bodega principal', is_active: true },
        quantity: 15,
        type_total: 15,
        min_stock: 20,
        below_min: true,
      },
    ],
    totals: {
      serialized: 8,
      by_status: { available: 6, assigned: 2, maintenance: 0, retired: 0 },
      quantity_units: 15,
      below_min_types: 1,
    },
    ...overrides,
  }
}

export function employeeReport(overrides: Partial<EmployeeReport> = {}): EmployeeReport {
  return {
    ...meta,
    filters: [{ label: 'Empleado', value: 'Ana Gómez' }],
    employee: { id: 9, full_name: 'Ana Gómez', position: 'Bombero', is_active: true },
    holdings: [
      {
        delivery: { id: 21, code: 'ENT-000021', kind: 'prestamo', kind_label: 'Préstamo', due_on: '2026-10-10', status: 'overdue' },
        asset: { id: 7, plate: 'B31-0001' },
        asset_type: { id: 3, name: 'Cilindro SCBA', tracking_mode: 'serialized' },
        pending_quantity: 1,
        delivered_at: '2026-10-01T14:00:00Z',
      },
      {
        delivery: { id: 22, code: 'ENT-000022', kind: 'dotacion', kind_label: 'Dotación', due_on: null, status: 'open' },
        asset: null,
        asset_type: { id: 5, name: 'Guantes de rescate', tracking_mode: 'quantity' },
        pending_quantity: 2,
        delivered_at: '2026-10-02T14:00:00Z',
      },
    ],
    history: [
      {
        date: '2026-10-05T16:00:00Z',
        event: 'return',
        event_label: 'Devolución',
        delivery: { id: 22, code: 'ENT-000022', kind: 'dotacion', kind_label: 'Dotación' },
        asset: null,
        asset_type: { id: 5, name: 'Guantes de rescate' },
        quantity: 1,
        condition: 'damaged',
        condition_label: 'Dañado',
      },
      {
        date: '2026-10-02T14:00:00Z',
        event: 'delivery',
        event_label: 'Entrega',
        delivery: { id: 22, code: 'ENT-000022', kind: 'dotacion', kind_label: 'Dotación' },
        asset: null,
        asset_type: { id: 5, name: 'Guantes de rescate' },
        quantity: 3,
        condition: null,
        condition_label: null,
      },
    ],
    totals: { pending_units: 3, delivered_units: 3, returned_units: 1 },
    ...overrides,
  }
}

export function assetReport(overrides: Partial<AssetReport> = {}): AssetReport {
  return {
    ...meta,
    filters: [{ label: 'Placa', value: 'B31-0001' }],
    asset: {
      id: 7,
      plate: 'B31-0001',
      serial: 'SN-123',
      brand: 'MSA',
      model: 'G1',
      status: 'maintenance',
      status_label: 'En mantenimiento',
      asset_type: { id: 3, name: 'Cilindro SCBA', category: { id: 1, name: 'EPP' } },
      location: { id: 4, name: 'Bodega principal' },
      holder: null,
      acquisition_date: '2024-02-10',
      expires_on: '2034-02-10',
      next_maintenance_on: '2027-03-10',
    },
    events: [
      {
        id: 30,
        event: 'maintenance_done',
        event_label: 'Mantenimiento realizado',
        detail: 'Prueba hidrostática',
        user: { id: 1, name: 'Administrador' },
        created_at: '2026-10-03T13:00:00Z',
      },
    ],
    totals: { events: 1 },
    ...overrides,
  }
}

export function assetTypeReport(overrides: Partial<AssetTypeReport> = {}): AssetTypeReport {
  return {
    ...meta,
    filters: [{ label: 'Tipo', value: 'Guantes de rescate' }],
    asset_type: { id: 5, name: 'Guantes de rescate', category: { id: 1, name: 'EPP' }, min_stock: 20 },
    stock: [
      { location: { id: 4, name: 'Bodega principal', is_active: true }, quantity: 10 },
      { location: { id: 8, name: 'Máquina 1', is_active: true }, quantity: 5 },
    ],
    movements: [
      {
        id: 40,
        type: 'entry',
        type_label: 'Entrada',
        quantity: 20,
        from_location: null,
        to_location: { id: 4, name: 'Bodega principal' },
        reason: 'Compra',
        delivery: null,
        user: { id: 1, name: 'Administrador' },
        created_at: '2026-10-01T13:00:00Z',
      },
      {
        id: 41,
        type: 'delivery',
        type_label: 'Entrega',
        quantity: 3,
        from_location: { id: 4, name: 'Bodega principal' },
        to_location: null,
        reason: null,
        delivery: { id: 22, code: 'ENT-000022' },
        user: { id: 1, name: 'Administrador' },
        created_at: '2026-10-02T14:00:00Z',
      },
    ],
    totals: { stock_total: 15, below_min: true, movements: 2 },
    ...overrides,
  }
}

export function dashboard(overrides: Partial<Dashboard> = {}): Dashboard {
  return {
    assets: {
      by_status: { available: 40, assigned: 12, maintenance: 3, retired: 5 },
      total: 60,
      alerts: {
        maintenance: [
          {
            plan_id: 3,
            plan_name: 'Prueba hidrostática',
            next_due_on: '2026-10-01',
            overdue: true,
            asset: { id: 7, plate: 'B31-0001', asset_type: { id: 3, name: 'Cilindro SCBA' } },
          },
        ],
        expirations: [
          { expires_on: '2026-11-01', overdue: false, asset: { id: 8, plate: 'B31-0002', asset_type: { id: 3, name: 'Cilindro SCBA' } } },
        ],
      },
    },
    inventory: {
      below_min: [{ asset_type: { id: 5, name: 'Guantes de rescate', category: { id: 1, name: 'EPP' } }, type_total: 15, min_stock: 20 }],
    },
    deliveries: {
      open_count: 7,
      overdue_count: 1,
      overdue: [
        { id: 21, code: 'ENT-000021', employee: { id: 9, full_name: 'Ana Gómez', position: 'Bombero' }, due_on: '2026-10-10', pending_quantity: 1 },
      ],
    },
    ...overrides,
  }
}
