import { beforeEach, describe, expect, it, vi } from 'vitest'
import { assetsApi, settingsApi } from './api'
import type { AssetFormValues } from './schemas'

const apiFetch = vi.fn()

vi.mock('../../lib/api-client', () => ({ apiFetch: (...args: unknown[]) => apiFetch(...args) }))

const values: AssetFormValues = {
  asset_type_id: 3,
  plate: 'B31-0001',
  serial: null,
  brand: 'MSA',
  model: 'G1',
  acquisition_date: '2024-02-10',
  acquisition_value: 1250000.5,
  useful_life_months: 120,
  expires_on: null,
  location_id: 4,
  notes: null,
}

function sentFormData(call: unknown[]): FormData {
  const init = call[1] as RequestInit
  expect(init.body).toBeInstanceOf(FormData)
  return init.body as FormData
}

// Contrato: specs/005-modulo-activos/plan.md (FR-007: ver = activos.ver; modificar = activos.gestionar).
describe('API de activos (005, plan.md)', () => {
  beforeEach(() => {
    apiFetch.mockReset().mockResolvedValue({ data: [] })
  })

  it('lista con búsqueda, filtros de categoría, tipo, estado, ubicación y poseedor, paginado', async () => {
    await assetsApi.list({
      search: ' b31 ',
      assetCategoryId: 1,
      assetTypeId: 2,
      status: 'maintenance',
      locationId: 4,
      holderEmployeeId: 9,
      page: 2,
      perPage: 25,
    })
    expect(apiFetch).toHaveBeenCalledWith(
      '/api/assets?search=b31&asset_category_id=1&asset_type_id=2&status=maintenance&location_id=4&holder_employee_id=9&page=2&per_page=25'
    )

    await assetsApi.list()
    expect(apiFetch).toHaveBeenLastCalledWith('/api/assets')
  })

  it('obtiene la ficha', async () => {
    await assetsApi.get(7)
    expect(apiFetch).toHaveBeenCalledWith('/api/assets/7')
  })

  it('crea con POST multipart, con la foto opcional; los nulos viajan vacíos', async () => {
    const photo = new File(['x'], 'casco.jpg', { type: 'image/jpeg' })
    await assetsApi.create(values, photo)

    const [path, init] = apiFetch.mock.calls[0]!
    expect(path).toBe('/api/assets')
    expect((init as RequestInit).method).toBe('POST')
    const body = sentFormData(apiFetch.mock.calls[0]!)
    expect(body.get('plate')).toBe('B31-0001')
    expect(body.get('asset_type_id')).toBe('3')
    expect(body.get('acquisition_value')).toBe('1250000.5')
    expect(body.get('useful_life_months')).toBe('120')
    expect(body.get('serial')).toBe('')
    expect(body.get('expires_on')).toBe('')
    expect(body.get('photo')).toBeInstanceOf(File)

    await assetsApi.create(values)
    expect(sentFormData(apiFetch.mock.calls[1]!).has('photo')).toBe(false)
  })

  it('edita con PATCH JSON y gestiona la foto aparte (POST/DELETE /photo); sin eliminar activos', async () => {
    const { location_id: _location, ...updateValues } = values
    await assetsApi.update(7, updateValues)
    expect(apiFetch).toHaveBeenCalledWith('/api/assets/7', { method: 'PATCH', body: JSON.stringify(updateValues) })

    const photo = new File(['x'], 'casco.png', { type: 'image/png' })
    await assetsApi.uploadPhoto(7, photo)
    expect(apiFetch.mock.calls[1]![0]).toBe('/api/assets/7/photo')
    expect((apiFetch.mock.calls[1]![1] as RequestInit).method).toBe('POST')
    expect(sentFormData(apiFetch.mock.calls[1]!).get('photo')).toBe(photo)

    await assetsApi.deletePhoto(7)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/assets/7/photo', { method: 'DELETE' })

    expect(assetsApi).not.toHaveProperty('remove')
    expect(assetsApi).not.toHaveProperty('delete')
  })

  it('cambia estado (con motivo) y ubicación con PATCH (D-001)', async () => {
    await assetsApi.updateStatus(7, { status: 'retired', reason: 'Daño irreparable' })
    expect(apiFetch).toHaveBeenCalledWith('/api/assets/7/status', {
      method: 'PATCH',
      body: JSON.stringify({ status: 'retired', reason: 'Daño irreparable' }),
    })

    await assetsApi.updateLocation(7, 4)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/assets/7/location', {
      method: 'PATCH',
      body: JSON.stringify({ location_id: 4 }),
    })
  })

  it('historial paginado (D-002)', async () => {
    await assetsApi.listEvents(7, { page: 2 })
    expect(apiFetch).toHaveBeenCalledWith('/api/assets/7/events?page=2')
  })

  it('planes de mantenimiento: listar, crear y editar (RN-029)', async () => {
    const plan = { name: 'Prueba hidrostática', interval_months: 60, last_done_on: null, alert_days: 30, is_active: true }
    await assetsApi.maintenancePlans.list(7)
    expect(apiFetch).toHaveBeenCalledWith('/api/assets/7/maintenance-plans')

    await assetsApi.maintenancePlans.create(7, plan)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/assets/7/maintenance-plans', { method: 'POST', body: JSON.stringify(plan) })

    await assetsApi.maintenancePlans.update(7, 3, { ...plan, is_active: false })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/assets/7/maintenance-plans/3', {
      method: 'PATCH',
      body: JSON.stringify({ ...plan, is_active: false }),
    })
  })

  it('registros de mantenimiento: listar (paginado) y crear; sin editar ni borrar (SC-003)', async () => {
    const record = { plan_id: 3, performed_on: '2026-10-01', description: 'Prueba aprobada', performed_by: null, cost: null }
    await assetsApi.maintenanceRecords.list(7, { page: 2 })
    expect(apiFetch).toHaveBeenCalledWith('/api/assets/7/maintenance-records?page=2')

    await assetsApi.maintenanceRecords.create(7, record)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/assets/7/maintenance-records', { method: 'POST', body: JSON.stringify(record) })

    expect(assetsApi.maintenanceRecords).not.toHaveProperty('update')
  })

  it('alertas de mantenimientos y vencimientos (D-004)', async () => {
    await assetsApi.alerts()
    expect(apiFetch).toHaveBeenCalledWith('/api/assets/alerts')
  })

  it('ajustes globales GET/PUT /api/settings (D-005)', async () => {
    await settingsApi.get()
    expect(apiFetch).toHaveBeenCalledWith('/api/settings')

    await settingsApi.update({ expiry_alert_days: 45 })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/settings', { method: 'PUT', body: JSON.stringify({ expiry_alert_days: 45 }) })
  })
})
