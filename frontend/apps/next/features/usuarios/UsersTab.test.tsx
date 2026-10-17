import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Paginated, RoleRecord, UserRecord } from 'app/features/usuarios/api'
import { ApiError } from 'app/lib/api-client'
import { UsersTab } from './UsersTab'

const listUsers = vi.fn()
const listRoles = vi.fn()
const updateUserStatus = vi.fn()
const unlockUser = vi.fn()
const toastSuccess = vi.fn()
const toastError = vi.fn()
const can = vi.fn()

vi.mock('app/features/usuarios/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/usuarios/api')>()),
  listUsers: (...args: unknown[]) => listUsers(...args),
  listRoles: (...args: unknown[]) => listRoles(...args),
  updateUserStatus: (...args: unknown[]) => updateUserStatus(...args),
  unlockUser: (...args: unknown[]) => unlockUser(...args),
}))
vi.mock('app/features/empleados/api', () => ({
  employeesOptions: () => Promise.resolve({ data: [] }),
}))
vi.mock('app/provider/auth', () => ({
  useCan: (code: string) => can(code),
  useAuth: () => ({ user: { id: 1 } }),
}))
vi.mock('sonner', () => ({
  toast: { success: (...args: unknown[]) => toastSuccess(...args), error: (...args: unknown[]) => toastError(...args) },
}))

function role(overrides: Partial<RoleRecord>): RoleRecord {
  return { id: 1, code: null, name: 'Rol', description: null, is_system: true, is_active: true, users_count: 0, permissions: [], ...overrides }
}

function user(overrides: Partial<UserRecord>): UserRecord {
  return {
    id: 1,
    name: 'Administrador',
    username: 'admin',
    email: 'admin@activosb31.local',
    is_active: true,
    must_change_password: false,
    last_login_at: '2026-10-17T14:30:00Z',
    role: { id: 1, name: 'Administrador', code: 'admin' },
    created_at: '2026-10-17T10:00:00Z',
    locked_until: null,
    employee: null,
    ...overrides,
  }
}

function page(data: UserRecord[], meta: Partial<Paginated<UserRecord>['meta']> = {}): Paginated<UserRecord> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 20, total: data.length, from: 1, to: data.length, ...meta } }
}

const users = [
  user({}),
  user({
    id: 9,
    name: 'Juan Pérez',
    username: 'jperez',
    email: null,
    must_change_password: true,
    last_login_at: null,
    role: { id: 3, name: 'Consulta', code: 'consulta' },
  }),
  user({ id: 10, name: 'Ana Inactiva', username: 'ana', is_active: false, role: { id: 2, name: 'Almacenista', code: 'almacenista' } }),
]

describe('UsersTab (US2, FR-007)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    listUsers.mockResolvedValue(page(users))
    listRoles.mockResolvedValue({
      data: [role({ id: 1, code: 'admin', name: 'Administrador' }), role({ id: 2, name: 'Almacenista' }), role({ id: 3, name: 'Consulta' })],
    })
  })

  it('lista usuarios con rol, estado y cambio de contraseña pendiente', async () => {
    render(<UsersTab />)

    const juan = await screen.findByRole('row', { name: /Juan Pérez/ })
    expect(within(juan).getByText('jperez')).toBeInTheDocument()
    expect(within(juan).getByText('Consulta')).toBeInTheDocument()
    expect(within(juan).getByText('Cambio de contraseña pendiente')).toBeInTheDocument()
    expect(within(juan).getByText('Nunca')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Ana Inactiva/ })).getByText('Inactivo')).toBeInTheDocument()
    expect(listUsers).toHaveBeenCalledWith({ search: '', roleId: undefined, isActive: undefined, page: 1, perPage: 20 })
  })

  it('busca por texto', async () => {
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar usuarios' }), 'juan')

    await waitFor(() => expect(listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'juan', page: 1 })))
  })

  it('filtra por rol y por estado', async () => {
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    fireEvent.click(screen.getByRole('combobox', { name: 'Filtrar por rol' }))
    const consulta = await screen.findByRole('option', { name: 'Consulta' })
    await act(async () => {
      fireEvent.pointerDown(consulta)
      fireEvent.click(consulta)
    })
    await waitFor(() => expect(listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ roleId: 3 })))

    fireEvent.click(screen.getByRole('combobox', { name: 'Filtrar por estado' }))
    const inactivos = await screen.findByRole('option', { name: 'Inactivos' })
    await act(async () => {
      fireEvent.pointerDown(inactivos)
      fireEvent.click(inactivos)
    })
    await waitFor(() => expect(listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ roleId: 3, isActive: false })))
  })

  it('pagina con el servidor', async () => {
    listUsers.mockResolvedValue(page(users, { last_page: 2, total: 23 }))
    render(<UsersTab />)
    await screen.findByText('Página 1 de 2 · 23 usuarios')

    await userEvent.click(screen.getByRole('button', { name: 'Página siguiente' }))

    await waitFor(() => expect(listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })))
  })

  it('sin "usuarios.gestionar" no muestra acciones (US5)', async () => {
    can.mockReturnValue(false)
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    expect(screen.queryByRole('button', { name: 'Nuevo usuario' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Editar/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Desactivar/ })).not.toBeInTheDocument()
  })

  it('no permite desactivar la propia cuenta (FR-012)', async () => {
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    expect(screen.getByRole('button', { name: 'Desactivar Administrador' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Desactivar Juan Pérez' })).toBeEnabled()
  })

  it('no ofrece restablecer la propia contraseña (RN-018)', async () => {
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    expect(screen.queryByRole('button', { name: 'Restablecer contraseña de Administrador' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Restablecer contraseña de Juan Pérez' })).toBeInTheDocument()
  })

  it('desactiva un usuario tras confirmar y recarga (US2 escenario 4)', async () => {
    updateUserStatus.mockResolvedValue({ data: { ...users[1], is_active: false } })
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Juan Pérez' }))
    const confirm = await screen.findByRole('alertdialog')
    expect(within(confirm).getByText(/sesiones abiertas se cerrarán/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))

    expect(updateUserStatus).toHaveBeenCalledWith(9, false)
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Usuario jperez desactivado.'))
    expect(listUsers).toHaveBeenCalledTimes(2)
  })

  it('muestra el 409 del último Administrador', async () => {
    updateUserStatus.mockRejectedValue(new ApiError('Debe quedar al menos un Administrador activo.', 409))
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Juan Pérez' }))
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Desactivar' }))

    await waitFor(() => expect(toastError).toHaveBeenCalledWith('Debe quedar al menos un Administrador activo.'))
  })

  it('abre los diálogos de crear, editar y restablecer contraseña', async () => {
    render(<UsersTab />)
    await screen.findByRole('row', { name: /Juan Pérez/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo usuario' }))
    expect(await screen.findByRole('dialog', { name: 'Nuevo usuario' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }))

    await userEvent.click(screen.getByRole('button', { name: 'Editar Juan Pérez' }))
    expect(await screen.findByRole('dialog', { name: 'Editar usuario' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }))

    await userEvent.click(screen.getByRole('button', { name: 'Restablecer contraseña de Juan Pérez' }))
    expect(await screen.findByRole('dialog', { name: 'Restablecer contraseña' })).toBeInTheDocument()
  })

  // RN-034 / FR-004 (003-seguridad-bitacora): estado de bloqueo y desbloqueo.
  it('muestra el empleado vinculado debajo del nombre (RN-015)', async () => {
    listUsers.mockResolvedValue(page([user({ id: 9, name: 'Juan Pérez', username: 'jperez', employee: { id: 4, full_name: 'Juan A. Pérez Gómez' } })]))
    render(<UsersTab />)

    const row = (await screen.findByText('Juan Pérez')).closest('tr')!
    expect(within(row).getByText('Empleado: Juan A. Pérez Gómez')).toBeInTheDocument()
  })

  describe('bloqueo por intentos fallidos (RN-034)', () => {
    const lockedUntil = new Date(Date.now() + 10 * 60_000).toISOString()
    const expectedTime = new Intl.DateTimeFormat('es-CO', { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(
      new Date(lockedUntil)
    )
    const lockedUsers = [
      users[0],
      user({ id: 9, name: 'Juan Pérez', username: 'jperez', role: null, locked_until: lockedUntil }),
      user({ id: 11, name: 'Pedro Vencido', username: 'pedro', role: null, locked_until: new Date(Date.now() - 60_000).toISOString() }),
    ]

    beforeEach(() => {
      listUsers.mockResolvedValue(page(lockedUsers))
    })

    it('muestra "Bloqueada hasta HH:MM" solo si el bloqueo es futuro', async () => {
      render(<UsersTab />)

      const juan = await screen.findByRole('row', { name: /Juan Pérez/ })
      expect(within(juan).getByText(`Bloqueada hasta ${expectedTime}`)).toBeInTheDocument()
      expect(within(screen.getByRole('row', { name: /Pedro Vencido/ })).queryByText(/Bloqueada/)).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Desbloquear Pedro Vencido' })).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Desbloquear Administrador' })).not.toBeInTheDocument()
    })

    it('desbloquea tras confirmar, avisa y recarga (FR-004)', async () => {
      unlockUser.mockResolvedValue({ data: { ...lockedUsers[1], locked_until: null } })
      render(<UsersTab />)
      await screen.findByRole('row', { name: /Juan Pérez/ })

      await userEvent.click(screen.getByRole('button', { name: 'Desbloquear Juan Pérez' }))
      const confirm = await screen.findByRole('alertdialog')
      await userEvent.click(within(confirm).getByRole('button', { name: 'Desbloquear' }))

      expect(unlockUser).toHaveBeenCalledWith(9)
      await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Cuenta jperez desbloqueada.'))
      expect(listUsers).toHaveBeenCalledTimes(2)
    })

    it('muestra el error si el desbloqueo falla', async () => {
      unlockUser.mockRejectedValue(new ApiError('Acción no autorizada.', 403))
      render(<UsersTab />)
      await screen.findByRole('row', { name: /Juan Pérez/ })

      await userEvent.click(screen.getByRole('button', { name: 'Desbloquear Juan Pérez' }))
      await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Desbloquear' }))

      await waitFor(() => expect(toastError).toHaveBeenCalledWith('Acción no autorizada.'))
    })

    it('sin "usuarios.gestionar" ve la insignia pero no la acción', async () => {
      can.mockReturnValue(false)
      render(<UsersTab />)

      const juan = await screen.findByRole('row', { name: /Juan Pérez/ })
      expect(within(juan).getByText(`Bloqueada hasta ${expectedTime}`)).toBeInTheDocument()
      expect(screen.queryByRole('button', { name: /Desbloquear/ })).not.toBeInTheDocument()
    })
  })
})
