import { beforeEach, describe, expect, it, vi } from 'vitest'
import { inventoryApi } from './api'

const apiFetch = vi.fn()

vi.mock('../../lib/api-client', () => ({ apiFetch: (...args: unknown[]) => apiFetch(...args) }))

function sentJson(call: unknown[]): unknown {
  const init = call[1] as RequestInit
  return JSON.parse(String(init.body))
}

// Contrato: specs/006-modulo-inventario/plan.md (FR-006: ver = inventario.ver; modificar = inventario.gestionar).
describe('API de inventario (006, plan.md)', () => {
  beforeEach(() => {
    apiFetch.mockReset().mockResolvedValue({ data: [] })
  })

  it('consulta existencias con filtros de categoría, tipo, ubicación y bajo mínimo, paginado (US2, FR-003)', async () => {
    await inventoryApi.stock({ assetCategoryId: 1, assetTypeId: 5, locationId: 4, belowMin: true, page: 2, perPage: 25 })
    expect(apiFetch).toHaveBeenCalledWith('/api/inventory/stock?asset_category_id=1&asset_type_id=5&location_id=4&below_min=1&page=2&per_page=25')

    await inventoryApi.stock()
    expect(apiFetch).toHaveBeenLastCalledWith('/api/inventory/stock')

    // "Solo bajo mínimo" apagado = sin filtro.
    await inventoryApi.stock({ belowMin: false })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/inventory/stock')
  })

  it('resume los serializados por tipo, ubicación y estado (D-005)', async () => {
    await inventoryApi.serializedSummary()
    expect(apiFetch).toHaveBeenCalledWith('/api/inventory/serialized-summary')
  })

  it('lista movimientos con filtros de clase, tipo, ubicación y rango de fechas', async () => {
    await inventoryApi.movements.list({
      type: 'transfer',
      assetTypeId: 5,
      locationId: 4,
      from: '2026-10-01',
      to: '2026-10-17',
      page: 3,
      perPage: 25,
    })
    expect(apiFetch).toHaveBeenCalledWith(
      '/api/inventory/movements?type=transfer&asset_type_id=5&location_id=4&from=2026-10-01&to=2026-10-17&page=3&per_page=25'
    )
  })

  it('registra un movimiento con POST JSON (no hay edición ni borrado: D-002)', async () => {
    await inventoryApi.movements.create({ type: 'entry', asset_type_id: 5, quantity: 20, to_location_id: 4, reason: null })
    expect(apiFetch.mock.calls[0]![0]).toBe('/api/inventory/movements')
    expect((apiFetch.mock.calls[0]![1] as RequestInit).method).toBe('POST')
    expect(sentJson(apiFetch.mock.calls[0]!)).toEqual({ type: 'entry', asset_type_id: 5, quantity: 20, to_location_id: 4, reason: null })
    expect(Object.keys(inventoryApi.movements)).toEqual(['list', 'create'])
  })

  it('conteos: listar, ver, abrir, registrar renglones y cerrar (US3, RN-027)', async () => {
    await inventoryApi.counts.list({ status: 'open', locationId: 4, page: 1 })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/inventory/counts?status=open&location_id=4&page=1')

    await inventoryApi.counts.get(12)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/inventory/counts/12')

    await inventoryApi.counts.create({ location_id: 4, notes: null })
    expect(apiFetch.mock.lastCall![0]).toBe('/api/inventory/counts')
    expect((apiFetch.mock.lastCall![1] as RequestInit).method).toBe('POST')
    expect(sentJson(apiFetch.mock.lastCall!)).toEqual({ location_id: 4, notes: null })

    await inventoryApi.counts.updateLines(12, [{ id: 1, counted_quantity: 13 }, { id: 2, asset_id: 9, found: false }])
    expect(apiFetch.mock.lastCall![0]).toBe('/api/inventory/counts/12/lines')
    expect((apiFetch.mock.lastCall![1] as RequestInit).method).toBe('PATCH')
    expect(sentJson(apiFetch.mock.lastCall!)).toEqual({ lines: [{ id: 1, counted_quantity: 13 }, { id: 2, asset_id: 9, found: false }] })

    await inventoryApi.counts.close(12)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/inventory/counts/12/close', { method: 'POST' })
  })

  it('consulta las alertas de stock mínimo (RN-028)', async () => {
    await inventoryApi.alerts()
    expect(apiFetch).toHaveBeenCalledWith('/api/inventory/alerts')
  })
})
