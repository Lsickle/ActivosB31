import { beforeEach, describe, expect, it, vi } from 'vitest'
import { deliveriesApi, employeeHoldings } from './api'
import type { DeliveryPayload, ReturnPayload } from './schemas'

const apiFetch = vi.fn()
const apiDownload = vi.fn()

vi.mock('../../lib/api-client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
  apiDownload: (...args: unknown[]) => apiDownload(...args),
}))

// Contrato: specs/007-modulo-entregas/plan.md ("Contrato (resumen)").
describe('API de entregas (007, plan.md)', () => {
  beforeEach(() => {
    apiFetch.mockReset().mockResolvedValue({ data: [] })
  })

  it('lista con búsqueda, filtros de empleado, tipo, estado y fechas, y paginación (US3)', async () => {
    await deliveriesApi.list({
      search: ' ENT-0001 ',
      employeeId: 10,
      kind: 'prestamo',
      status: 'overdue',
      from: '2026-10-01',
      to: '2026-10-17',
      page: 2,
      perPage: 25,
    })
    expect(apiFetch).toHaveBeenCalledWith(
      '/api/deliveries?search=ENT-0001&employee_id=10&kind=prestamo&status=overdue&from=2026-10-01&to=2026-10-17&page=2&per_page=25'
    )

    await deliveriesApi.list()
    expect(apiFetch).toHaveBeenLastCalledWith('/api/deliveries')
  })

  it('descarga el acta en PDF con apiDownload (sesión y CSRF)', async () => {
    const file = { blob: new Blob(['%PDF']), filename: 'ENT-000005.pdf' }
    apiDownload.mockResolvedValue(file)
    await expect(deliveriesApi.downloadPdf(5)).resolves.toBe(file)
    expect(apiDownload).toHaveBeenCalledWith('/api/deliveries/5/pdf')
  })

  it('obtiene la ficha con ítems, devoluciones y firma', async () => {
    await deliveriesApi.get(5)
    expect(apiFetch).toHaveBeenCalledWith('/api/deliveries/5')
  })

  it('crea con POST la entrega firmada (D-003, RN-024, RN-025)', async () => {
    const payload: DeliveryPayload = {
      employee_id: 10,
      kind: 'prestamo',
      due_on: '2026-10-30',
      from_location_id: 4,
      notes: null,
      signature: 'data:image/png;base64,AAAA',
      items: [{ asset_id: 7 }, { asset_type_id: 5, quantity: 2 }],
    }
    await deliveriesApi.create(payload)
    expect(apiFetch).toHaveBeenCalledWith('/api/deliveries', { method: 'POST', body: JSON.stringify(payload) })
  })

  it('registra devoluciones con POST /returns (D-004, RN-026); sin editar ni borrar (FR-001)', async () => {
    const payload: ReturnPayload = {
      items: [
        { delivery_item_id: 1, quantity: 1, condition: 'good', to_location_id: 4 },
        { delivery_item_id: 2, quantity: 1, condition: 'lost', notes: 'Se perdió en servicio' },
      ],
    }
    await deliveriesApi.createReturn(5, payload)
    expect(apiFetch).toHaveBeenCalledWith('/api/deliveries/5/returns', { method: 'POST', body: JSON.stringify(payload) })

    for (const forbidden of ['update', 'remove', 'delete']) {
      expect(deliveriesApi).not.toHaveProperty(forbidden)
    }
  })

  it('consulta las alertas de préstamos vencidos (FR-005)', async () => {
    await deliveriesApi.alerts()
    expect(apiFetch).toHaveBeenCalledWith('/api/deliveries/alerts')
  })

  it('consulta las tenencias de un empleado (D-007)', async () => {
    await employeeHoldings(10)
    expect(apiFetch).toHaveBeenCalledWith('/api/employees/10/holdings')
  })
})
