import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  assetCategoriesApi,
  assetTypesApi,
  companiesApi,
  getConfigurationOptions,
  locationTypesApi,
  locationsApi,
  positionsApi,
} from './api'

const apiFetch = vi.fn()

vi.mock('../../lib/api-client', () => ({ apiFetch: (...args: unknown[]) => apiFetch(...args) }))

// Contrato: specs/002-modulo-configuracion/contracts/api.md
describe('API de configuración (contracts/api.md)', () => {
  beforeEach(() => {
    apiFetch.mockReset().mockResolvedValue({ data: [] })
  })

  it('lista categorías con búsqueda, estado (1/0) y paginación, omitiendo vacíos', async () => {
    await assetCategoriesApi.list({ search: ' epp ', isActive: false, page: 2, perPage: 25 })
    expect(apiFetch).toHaveBeenCalledWith('/api/asset-categories?search=epp&is_active=0&page=2&per_page=25')

    await assetCategoriesApi.list({ search: '' })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/asset-categories')
  })

  it('lista tipos de activo con filtros de categoría y modo de control (RN-007)', async () => {
    await assetTypesApi.list({ assetCategoryId: 3, trackingMode: 'quantity', isActive: true })
    expect(apiFetch).toHaveBeenCalledWith('/api/asset-types?is_active=1&asset_category_id=3&tracking_mode=quantity')
  })

  it('lista ubicaciones filtradas por tipo (RN-002)', async () => {
    await locationsApi.list({ locationTypeId: 4 })
    expect(apiFetch).toHaveBeenCalledWith('/api/locations?location_type_id=4')
  })

  it('crea con POST, edita con PATCH y cambia estado con PATCH /status (RN-003: sin DELETE)', async () => {
    await locationTypesApi.create({ name: 'Bodega', description: null })
    expect(apiFetch).toHaveBeenCalledWith('/api/location-types', {
      method: 'POST',
      body: JSON.stringify({ name: 'Bodega', description: null }),
    })

    await assetTypesApi.update(5, { name: 'Casco', description: null, asset_category_id: 1, tracking_mode: 'serialized' })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/asset-types/5', {
      method: 'PATCH',
      body: JSON.stringify({ name: 'Casco', description: null, asset_category_id: 1, tracking_mode: 'serialized' }),
    })

    await assetCategoriesApi.updateStatus(7, false)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/asset-categories/7/status', {
      method: 'PATCH',
      body: JSON.stringify({ is_active: false }),
    })

    for (const api of [assetCategoriesApi, assetTypesApi, locationTypesApi, locationsApi]) {
      expect(api).not.toHaveProperty('remove')
      expect(api).not.toHaveProperty('delete')
    }
  })

  // specs/004-modulo-empleados D-001: cargos (RN-035) y compañías (RN-036) siguen el patrón de 002.
  it('cargos y compañías usan /api/positions y /api/companies sin DELETE (004 D-001)', async () => {
    await positionsApi.list({ search: 'teniente', isActive: true })
    expect(apiFetch).toHaveBeenCalledWith('/api/positions?search=teniente&is_active=1')

    await companiesApi.create({ name: 'Compañía A', description: null })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/companies', {
      method: 'POST',
      body: JSON.stringify({ name: 'Compañía A', description: null }),
    })

    await positionsApi.update(3, { name: 'Sargento', description: null })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/positions/3', {
      method: 'PATCH',
      body: JSON.stringify({ name: 'Sargento', description: null }),
    })

    await companiesApi.updateStatus(2, false)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/companies/2/status', {
      method: 'PATCH',
      body: JSON.stringify({ is_active: false }),
    })

    for (const api of [positionsApi, companiesApi]) {
      expect(api).not.toHaveProperty('remove')
      expect(api).not.toHaveProperty('delete')
    }
  })

  it('obtiene las opciones disponibles (FR-010)', async () => {
    await getConfigurationOptions()
    expect(apiFetch).toHaveBeenCalledWith('/api/configuracion/options')
  })
})
