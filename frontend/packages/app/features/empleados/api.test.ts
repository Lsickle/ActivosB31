import { beforeEach, describe, expect, it, vi } from 'vitest'
import { employeesApi, employeesOptions } from './api'
import type { EmployeeFormValues } from './schemas'

const apiFetch = vi.fn()

vi.mock('../../lib/api-client', () => ({ apiFetch: (...args: unknown[]) => apiFetch(...args) }))

const values: EmployeeFormValues = {
  document_type: 'CC',
  document_number: '1234567',
  first_name: 'Ana',
  last_name: 'Pérez',
  position_id: 2,
  company_id: null,
  phone: null,
  email: null,
  hire_date: null,
  shirt_size: 'M',
  pants_size: null,
  boot_size: null,
  glove_size: null,
}

// Contrato: specs/004-modulo-empleados/plan.md (RN-022: ver/gestionar Empleados).
describe('API de empleados (004, plan.md)', () => {
  beforeEach(() => {
    apiFetch.mockReset().mockResolvedValue({ data: [] })
  })

  it('lista con búsqueda, filtros de cargo, compañía y estado (1/0) y paginación (FR-001)', async () => {
    await employeesApi.list({ search: ' ana ', positionId: 2, companyId: 5, isActive: false, page: 3, perPage: 25 })
    expect(apiFetch).toHaveBeenCalledWith('/api/employees?search=ana&position_id=2&company_id=5&is_active=0&page=3&per_page=25')

    await employeesApi.list()
    expect(apiFetch).toHaveBeenLastCalledWith('/api/employees')
  })

  it('crea con POST, edita con PATCH y cambia estado con PATCH /status; sin DELETE (US1-5)', async () => {
    await employeesApi.create(values)
    expect(apiFetch).toHaveBeenCalledWith('/api/employees', { method: 'POST', body: JSON.stringify(values) })

    await employeesApi.update(9, values)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/employees/9', { method: 'PATCH', body: JSON.stringify(values) })

    await employeesApi.updateStatus(9, false)
    expect(apiFetch).toHaveBeenLastCalledWith('/api/employees/9/status', {
      method: 'PATCH',
      body: JSON.stringify({ is_active: false }),
    })

    expect(employeesApi).not.toHaveProperty('remove')
    expect(employeesApi).not.toHaveProperty('delete')
  })

  it('obtiene las opciones mínimas de empleados activos (FR-006, D-004)', async () => {
    await employeesOptions()
    expect(apiFetch).toHaveBeenCalledWith('/api/employees/options')
  })
})
