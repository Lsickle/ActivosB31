import { beforeEach, describe, expect, it, vi } from 'vitest'
import { listRoles, listUsers, updateRolePermissions, updateUserStatus } from './api'

const apiFetch = vi.fn()

vi.mock('../../lib/api-client', () => ({ apiFetch: (...args: unknown[]) => apiFetch(...args) }))

describe('API de usuarios y roles (contracts/api.md)', () => {
  beforeEach(() => {
    apiFetch.mockReset().mockResolvedValue({ data: [] })
  })

  it('listUsers arma la query con los filtros definidos y omite los vacíos', async () => {
    await listUsers({ search: 'ana', roleId: 3, isActive: false, page: 2, perPage: 20 })
    expect(apiFetch).toHaveBeenCalledWith('/api/users?search=ana&role_id=3&is_active=0&page=2&per_page=20')

    await listUsers({ search: '' })
    expect(apiFetch).toHaveBeenLastCalledWith('/api/users')
  })

  it('listRoles filtra por activos', async () => {
    await listRoles({ isActive: true })
    expect(apiFetch).toHaveBeenCalledWith('/api/roles?is_active=1')
  })

  it('updateUserStatus hace PATCH con is_active', async () => {
    await updateUserStatus(7, false)
    expect(apiFetch).toHaveBeenCalledWith('/api/users/7/status', { method: 'PATCH', body: JSON.stringify({ is_active: false }) })
  })

  it('updateRolePermissions reemplaza el conjunto del rol con PUT', async () => {
    await updateRolePermissions(2, ['empleados.ver', 'empleados.gestionar'])
    expect(apiFetch).toHaveBeenCalledWith('/api/roles/2/permissions', {
      method: 'PUT',
      body: JSON.stringify({ permissions: ['empleados.ver', 'empleados.gestionar'] }),
    })
  })
})
