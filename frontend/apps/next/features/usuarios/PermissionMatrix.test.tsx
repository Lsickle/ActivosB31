import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PermissionModule, RoleRecord } from 'app/features/usuarios/api'
import { PermissionMatrix } from './PermissionMatrix'

const listPermissions = vi.fn()
const listRoles = vi.fn()
const updateRolePermissions = vi.fn()
const toastSuccess = vi.fn()
const toastError = vi.fn()
const can = vi.fn()

vi.mock('app/features/usuarios/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/usuarios/api')>()),
  listPermissions: () => listPermissions(),
  listRoles: (...args: unknown[]) => listRoles(...args),
  updateRolePermissions: (...args: unknown[]) => updateRolePermissions(...args),
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({
  toast: { success: (...args: unknown[]) => toastSuccess(...args), error: (...args: unknown[]) => toastError(...args) },
}))

const modules: PermissionModule[] = [
  {
    module: 'activos',
    label: 'Activos',
    permissions: [
      { code: 'activos.ver', action: 'ver' },
      { code: 'activos.gestionar', action: 'gestionar' },
    ],
  },
  {
    module: 'empleados',
    label: 'Empleados',
    permissions: [
      { code: 'empleados.ver', action: 'ver' },
      { code: 'empleados.gestionar', action: 'gestionar' },
    ],
  },
  { module: 'reportes', label: 'Reportes', permissions: [{ code: 'reportes.ver', action: 'ver' }] },
  {
    module: 'usuarios',
    label: 'Usuarios y roles',
    permissions: [
      { code: 'usuarios.ver', action: 'ver' },
      { code: 'usuarios.gestionar', action: 'gestionar' },
    ],
  },
]

function role(overrides: Partial<RoleRecord>): RoleRecord {
  return {
    id: 1,
    code: null,
    name: 'Rol',
    description: null,
    is_system: true,
    is_active: true,
    users_count: 0,
    permissions: [],
    ...overrides,
  }
}

const roles: RoleRecord[] = [
  role({
    id: 1,
    code: 'admin',
    name: 'Administrador',
    permissions: ['activos.gestionar', 'activos.ver', 'empleados.gestionar', 'empleados.ver', 'reportes.ver'],
  }),
  role({ id: 2, code: 'almacenista', name: 'Almacenista', permissions: ['activos.gestionar', 'activos.ver', 'empleados.ver', 'reportes.ver'] }),
  role({ id: 3, code: 'consulta', name: 'Consulta', permissions: ['activos.ver', 'empleados.ver', 'reportes.ver'] }),
]

function checkbox(name: string) {
  return screen.getByRole('checkbox', { name })
}

describe('PermissionMatrix (RN-009, RN-010, US1)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockImplementation((code: string) => code === 'usuarios.gestionar' || code === 'usuarios.ver')
    listPermissions.mockResolvedValue({ data: modules })
    listRoles.mockResolvedValue({ data: roles })
  })

  it('arma filas por módulo y columnas por rol con el estado de cada casilla', async () => {
    render(<PermissionMatrix />)

    expect(await screen.findByRole('columnheader', { name: /Almacenista/ })).toBeInTheDocument()
    expect(screen.getByRole('rowheader', { name: 'Empleados' })).toBeInTheDocument()
    expect(checkbox('Ver Empleados · Almacenista')).toBeChecked()
    expect(checkbox('Gestionar Empleados · Almacenista')).not.toBeChecked()
    expect(checkbox('Gestionar Activos · Almacenista')).toBeChecked()
  })

  it('Reportes solo tiene "Ver" (FR-001)', async () => {
    render(<PermissionMatrix />)

    await screen.findByRole('rowheader', { name: 'Reportes' })
    expect(checkbox('Ver Reportes · Consulta')).toBeInTheDocument()
    expect(screen.queryByRole('checkbox', { name: 'Gestionar Reportes · Consulta' })).not.toBeInTheDocument()
  })

  it('la columna Administrador está bloqueada con todo marcado (RN-010)', async () => {
    render(<PermissionMatrix />)

    await screen.findByRole('columnheader', { name: /Administrador/ })
    const adminBox = checkbox('Gestionar Empleados · Administrador')
    expect(adminBox).toBeChecked()
    expect(adminBox).toHaveAttribute('aria-disabled', 'true')
    expect(screen.queryByRole('button', { name: 'Guardar Administrador' })).not.toBeInTheDocument()
  })

  it('marcar "Gestionar" marca "Ver" y guarda el rol con toast (US1 escenarios 1 y 3)', async () => {
    updateRolePermissions.mockResolvedValue({
      data: { ...roles[2], permissions: ['activos.ver', 'empleados.gestionar', 'empleados.ver', 'reportes.ver'] },
    })
    render(<PermissionMatrix />)
    await screen.findByRole('rowheader', { name: 'Empleados' })

    const guardar = screen.getByRole('button', { name: 'Guardar Consulta' })
    expect(guardar).toBeDisabled()

    await userEvent.click(checkbox('Ver Empleados · Consulta'))
    expect(checkbox('Ver Empleados · Consulta')).not.toBeChecked()
    await userEvent.click(checkbox('Gestionar Empleados · Consulta'))
    expect(checkbox('Ver Empleados · Consulta')).toBeChecked()
    expect(checkbox('Gestionar Empleados · Consulta')).toBeChecked()

    expect(guardar).toBeEnabled()
    await userEvent.click(guardar)

    expect(updateRolePermissions).toHaveBeenCalledWith(3, ['activos.ver', 'empleados.gestionar', 'empleados.ver', 'reportes.ver'])
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Permisos de Consulta guardados.'))
    expect(guardar).toBeDisabled()
  })

  it('quitar "Ver" quita "Gestionar" (US1 escenario 2)', async () => {
    render(<PermissionMatrix />)
    await screen.findByRole('rowheader', { name: 'Activos' })

    await userEvent.click(checkbox('Ver Activos · Almacenista'))

    expect(checkbox('Ver Activos · Almacenista')).not.toBeChecked()
    expect(checkbox('Gestionar Activos · Almacenista')).not.toBeChecked()
  })

  it('muestra el error del servidor al guardar y conserva los cambios', async () => {
    updateRolePermissions.mockRejectedValue(new Error('No tienes permiso.'))
    render(<PermissionMatrix />)
    await screen.findByRole('rowheader', { name: 'Activos' })

    await userEvent.click(checkbox('Gestionar Empleados · Almacenista'))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar Almacenista' }))

    await waitFor(() => expect(toastError).toHaveBeenCalledWith('No tienes permiso.'))
    expect(checkbox('Gestionar Empleados · Almacenista')).toBeChecked()
    expect(screen.getByRole('button', { name: 'Guardar Almacenista' })).toBeEnabled()
  })

  it('sin "usuarios.gestionar" la matriz es de solo lectura (US1 escenario 5)', async () => {
    can.mockImplementation((code: string) => code === 'usuarios.ver')
    render(<PermissionMatrix />)
    await screen.findByRole('rowheader', { name: 'Activos' })

    expect(checkbox('Ver Activos · Consulta')).toHaveAttribute('aria-disabled', 'true')
    expect(screen.queryByRole('button', { name: /^Guardar/ })).not.toBeInTheDocument()
    expect(screen.getByText(/solo lectura/i)).toBeInTheDocument()
  })

  // RN-019: gestionar Usuarios y roles es exclusivo del Administrador.
  it('"Gestionar Usuarios y roles" no se puede conceder a otros roles', async () => {
    render(<PermissionMatrix />)
    await screen.findByRole('rowheader', { name: 'Usuarios y roles' })

    const box = checkbox('Gestionar Usuarios y roles · Almacenista')
    expect(box).toHaveAttribute('aria-disabled', 'true')
    expect(box).not.toBeChecked()
    expect(screen.getAllByTitle('Exclusivo del Administrador').length).toBe(2)
    expect(checkbox('Ver Usuarios y roles · Almacenista')).not.toHaveAttribute('aria-disabled', 'true')
    expect(checkbox('Gestionar Usuarios y roles · Administrador')).toBeChecked()
  })

  it('muestra un error si no carga', async () => {
    listRoles.mockRejectedValue(new Error('Error inesperado (500).'))
    render(<PermissionMatrix />)

    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar la matriz de permisos.')
  })
})
