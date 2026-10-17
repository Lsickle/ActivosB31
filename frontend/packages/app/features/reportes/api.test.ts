import { beforeEach, describe, expect, it, vi } from 'vitest'
import { dashboardApi, reportPath, reportsApi } from './api'

const apiFetch = vi.fn()
const apiDownload = vi.fn()

vi.mock('../../lib/api-client', () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
  apiDownload: (...args: unknown[]) => apiDownload(...args),
}))

// Contrato: specs/008-modulo-reportes/contracts/api.md (reportes.ver; D-001..D-004).
describe('API de reportes (008)', () => {
  beforeEach(() => {
    apiFetch.mockReset().mockResolvedValue({ data: {} })
    apiDownload.mockReset().mockResolvedValue({ blob: new Blob(), filename: null })
  })

  it('inventario general con filtros de categoría, tipo, ubicación y estado, en JSON (US1, FR-001)', async () => {
    await reportsApi.inventory({ assetCategoryId: 1, assetTypeId: 3, locationId: 4, status: 'available' })
    expect(apiFetch).toHaveBeenCalledWith('/api/reports/inventory?asset_category_id=1&asset_type_id=3&location_id=4&status=available&format=json')

    await reportsApi.inventory({})
    expect(apiFetch).toHaveBeenLastCalledWith('/api/reports/inventory?format=json')
  })

  it('por empleado con rango de fechas (US2, RN-033)', async () => {
    await reportsApi.employee(9, { from: '2026-10-01', to: '2026-10-17' })
    expect(apiFetch).toHaveBeenCalledWith('/api/reports/employees/9?from=2026-10-01&to=2026-10-17&format=json')

    await reportsApi.employee(9, {})
    expect(apiFetch).toHaveBeenLastCalledWith('/api/reports/employees/9?format=json')
  })

  it('por artículo: activo serializado y tipo por cantidad con ubicación (US3)', async () => {
    await reportsApi.asset(7, { from: '2026-01-01', to: '2026-10-17' })
    expect(apiFetch).toHaveBeenCalledWith('/api/reports/assets/7?from=2026-01-01&to=2026-10-17&format=json')

    await reportsApi.assetType(5, { from: '2026-10-01', locationId: 4 })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/reports/asset-types/5?from=2026-10-01&location_id=4&format=json')
  })

  it('busca activos por placa para el reporte por artículo (incluye todos los estados)', async () => {
    await reportsApi.assetOptions('  b31-00 ')
    expect(apiFetch).toHaveBeenCalledWith('/api/reports/asset-options?search=b31-00')
  })

  it('busca empleados para el reporte, incluidos inactivos (sin datos personales, RN-022)', async () => {
    await reportsApi.employeeOptions('  ana ')
    expect(apiFetch).toHaveBeenCalledWith('/api/reports/employee-options?search=ana')
  })

  it('busca tipos por cantidad para el reporte, incluidos inactivos', async () => {
    await reportsApi.assetTypeOptions('guan')
    expect(apiFetch).toHaveBeenCalledWith('/api/reports/asset-type-options?search=guan')
  })

  it('descarga Excel y PDF con los mismos filtros que la pantalla (D-002, RN-032)', async () => {
    await reportsApi.download({ kind: 'inventory', filters: { locationId: 4, status: 'retired' } }, 'xlsx')
    expect(apiDownload).toHaveBeenCalledWith('/api/reports/inventory?location_id=4&status=retired&format=xlsx')

    await reportsApi.download({ kind: 'employee', employeeId: 9, range: { from: '2026-10-01' } }, 'pdf')
    expect(apiDownload).toHaveBeenLastCalledWith('/api/reports/employees/9?from=2026-10-01&format=pdf')

    await reportsApi.download({ kind: 'asset', assetId: 7, range: {} }, 'pdf')
    expect(apiDownload).toHaveBeenLastCalledWith('/api/reports/assets/7?format=pdf')

    await reportsApi.download({ kind: 'asset-type', assetTypeId: 5, filters: { to: '2026-10-17', locationId: 8 } }, 'xlsx')
    expect(apiDownload).toHaveBeenLastCalledWith('/api/reports/asset-types/5?to=2026-10-17&location_id=8&format=xlsx')
  })

  it('reportPath arma la misma consulta para json, xlsx y pdf', () => {
    const request = { kind: 'employee', employeeId: 2, range: { to: '2026-10-17' } } as const
    expect(reportPath(request, 'json')).toBe('/api/reports/employees/2?to=2026-10-17&format=json')
    expect(reportPath(request, 'pdf')).toBe('/api/reports/employees/2?to=2026-10-17&format=pdf')
  })
})

describe('API del tablero de Inicio (008 D-004)', () => {
  it('GET /api/dashboard', async () => {
    apiFetch.mockReset().mockResolvedValue({ data: {} })
    await dashboardApi.get()
    expect(apiFetch).toHaveBeenCalledWith('/api/dashboard')
  })
})
