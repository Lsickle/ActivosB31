import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RoleRecord } from 'app/features/usuarios/api'
import { ApiError, ApiValidationError } from 'app/lib/api-client'
import { RolesTab } from './RolesTab'

const listRoles = vi.fn()
const createRole = vi.fn()
const updateRole = vi.fn()
const updateRoleStatus = vi.fn()
const toastSuccess = vi.fn()
const toastError = vi.fn()
const can = vi.fn()

vi.mock('app/features/usuarios/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/usuarios/api')>()),
  listRoles: (...args: unknown[]) => listRoles(...args),
  createRole: (...args: unknown[]) => createRole(...args),
  updateRole: (...args: unknown[]) => updateRole(...args),
  updateRoleStatus: (...args: unknown[]) => updateRoleStatus(...args),
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({
  toast: { success: (...args: unknown[]) => toastSuccess(...args), error: (...args: unknown[]) => toastError(...args) },
}))

function role(overrides: Partial<RoleRecord>): RoleRecord {
  return {
    id: 1,
    code: null,
    name: 'Rol',
    description: null,
    is_system: false,
    is_active: true,
    users_count: 0,
    permissions: [],
    ...overrides,
  }
}

const roles: RoleRecord[] = [
  role({ id: 1, code: 'admin', name: 'Administrador', is_system: true, users_count: 1 }),
  role({ id: 3, code: 'consulta', name: 'Consulta', is_system: true, users_count: 4, description: 'Solo consulta' }),
  role({ id: 4, name: 'Comandante', users_count: 2 }),
  role({ id: 5, name: 'Temporal', is_active: false }),
]

function rowOf(name: string) {
  return screen.getByRole('row', { name: new RegExp(name) })
}

describe('RolesTab (US4, FR-013)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    listRoles.mockResolvedValue({ data: roles })
  })

  it('lista los roles con descripción, usuarios, estado y marca de sistema', async () => {
    render(<RolesTab />)

    const consulta = await screen.findByRole('row', { name: /Consulta/ })
    expect(within(consulta).getByText('Solo consulta')).toBeInTheDocument()
    expect(within(consulta).getByText('4')).toBeInTheDocument()
    expect(within(consulta).getByText('Del sistema')).toBeInTheDocument()
    expect(within(rowOf('Temporal')).getByText('Inactivo')).toBeInTheDocument()
  })

  it('rotula el conteo como "Usuarios activos" (RN-016)', async () => {
    render(<RolesTab />)

    expect(await screen.findByRole('columnheader', { name: 'Usuarios activos' })).toBeInTheDocument()
  })

  it('en un rol del sistema solo se edita la descripción (RN-020)', async () => {
    updateRole.mockResolvedValue({ data: role({ id: 3, code: 'consulta', name: 'Consulta', is_system: true }) })
    render(<RolesTab />)
    await screen.findByRole('row', { name: /Consulta/ })

    await userEvent.click(screen.getByRole('button', { name: 'Editar Consulta' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar rol' })
    expect(within(dialog).getByLabelText('Nombre')).toBeDisabled()
    const description = within(dialog).getByLabelText(/Descripción/)
    await userEvent.clear(description)
    await userEvent.type(description, 'Solo lectura')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(updateRole).toHaveBeenCalledWith(3, { name: 'Consulta', description: 'Solo lectura' })
  })

  it('sin "usuarios.gestionar" no muestra acciones', async () => {
    can.mockReturnValue(false)
    render(<RolesTab />)

    await screen.findByRole('row', { name: /Comandante/ })
    expect(screen.queryByRole('button', { name: 'Nuevo rol' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Editar/ })).not.toBeInTheDocument()
  })

  it('crea un rol sin permisos y recarga la lista (US4 escenario 1)', async () => {
    createRole.mockResolvedValue({ data: role({ id: 6, name: 'Bodega' }) })
    render(<RolesTab />)
    await screen.findByRole('row', { name: /Comandante/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo rol' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo rol' })
    await userEvent.type(within(dialog).getByLabelText('Nombre'), '  Bodega ')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(createRole).toHaveBeenCalledWith({ name: 'Bodega', description: null })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Rol "Bodega" creado. Asígnale permisos en la pestaña Permisos.'))
    expect(listRoles).toHaveBeenCalledTimes(2)
  })

  it('muestra en el campo el error 422 del backend', async () => {
    createRole.mockRejectedValue(new ApiValidationError('Inválido', { name: ['Ya existe un rol con ese nombre.'] }))
    render(<RolesTab />)
    await screen.findByRole('row', { name: /Comandante/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo rol' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo rol' })
    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'consulta')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByText('Ya existe un rol con ese nombre.')).toBeInTheDocument()
  })

  it('edita nombre y descripción', async () => {
    updateRole.mockResolvedValue({ data: role({ id: 4, name: 'Comandante de guardia' }) })
    render(<RolesTab />)
    await screen.findByRole('row', { name: /Comandante/ })

    await userEvent.click(screen.getByRole('button', { name: 'Editar Comandante' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar rol' })
    const name = within(dialog).getByLabelText('Nombre')
    expect(name).toHaveValue('Comandante')
    await userEvent.clear(name)
    await userEvent.type(name, 'Comandante de guardia')
    await userEvent.type(within(dialog).getByLabelText(/Descripción/), 'Jefe de turno')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(updateRole).toHaveBeenCalledWith(4, { name: 'Comandante de guardia', description: 'Jefe de turno' })
  })

  it('los roles del sistema no se pueden desactivar', async () => {
    render(<RolesTab />)
    await screen.findByRole('row', { name: /Comandante/ })

    expect(screen.getByRole('button', { name: 'Desactivar Consulta' })).toBeDisabled()
  })

  it('al desactivar muestra el conflicto 409 del servidor (US4 escenario 2)', async () => {
    updateRoleStatus.mockRejectedValue(new ApiError('No se puede desactivar: 2 usuarios activos tienen este rol.', 409))
    render(<RolesTab />)
    await screen.findByRole('row', { name: /Comandante/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Comandante' }))
    const confirm = await screen.findByRole('alertdialog')
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))

    expect(updateRoleStatus).toHaveBeenCalledWith(4, false)
    await waitFor(() => expect(toastError).toHaveBeenCalledWith('No se puede desactivar: 2 usuarios activos tienen este rol.'))
  })

  it('reactiva un rol inactivo', async () => {
    updateRoleStatus.mockResolvedValue({ data: role({ id: 5, name: 'Temporal' }) })
    render(<RolesTab />)
    await screen.findByRole('row', { name: /Temporal/ })

    await userEvent.click(screen.getByRole('button', { name: 'Activar Temporal' }))
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Activar' }))

    expect(updateRoleStatus).toHaveBeenCalledWith(5, true)
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Rol "Temporal" activado.'))
  })
})
