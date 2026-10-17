import { act, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RoleRecord, UserRecord } from 'app/features/usuarios/api'
import { ApiError, ApiValidationError } from 'app/lib/api-client'
import { UserFormDialog } from './UserFormDialog'

const createUser = vi.fn()
const updateUser = vi.fn()
const onSaved = vi.fn()
const employeesOptions = vi.fn()
const can = vi.fn()

vi.mock('app/features/usuarios/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/usuarios/api')>()),
  createUser: (...args: unknown[]) => createUser(...args),
  updateUser: (...args: unknown[]) => updateUser(...args),
}))
vi.mock('app/features/empleados/api', () => ({
  employeesOptions: (...args: unknown[]) => employeesOptions(...args),
}))
vi.mock('app/provider/auth', () => ({
  useCan: (code: string) => can(code),
}))

function role(overrides: Partial<RoleRecord>): RoleRecord {
  return { id: 1, code: null, name: 'Rol', description: null, is_system: true, is_active: true, users_count: 0, permissions: [], ...overrides }
}

const roles: RoleRecord[] = [
  role({ id: 1, code: 'admin', name: 'Administrador' }),
  role({ id: 2, code: 'almacenista', name: 'Almacenista' }),
  role({ id: 3, code: 'consulta', name: 'Consulta' }),
  role({ id: 5, name: 'Temporal', is_system: false, is_active: false }),
]

const existing: UserRecord = {
  id: 9,
  name: 'Juan Pérez',
  username: 'jperez',
  email: 'jperez@bomberos.gov.co',
  is_active: true,
  must_change_password: false,
  last_login_at: null,
  role: { id: 3, name: 'Consulta', code: 'consulta' },
  created_at: '2026-10-17T10:00:00Z',
  locked_until: null,
  employee: null,
}

function renderDialog(user: UserRecord | null = null) {
  render(<UserFormDialog open onOpenChange={vi.fn()} user={user} roles={roles} onSaved={onSaved} />)
  return screen.getByRole('dialog')
}

async function chooseRole(dialog: HTMLElement, name: string) {
  fireEvent.click(within(dialog).getByRole('combobox', { name: 'Rol' }))
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

async function fillCreate(dialog: HTMLElement, username = 'JPerez') {
  await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Juan Pérez')
  await userEvent.type(within(dialog).getByLabelText('Nombre de usuario'), username)
  await userEvent.type(within(dialog).getByLabelText(/Correo/), 'JPerez@Bomberos.gov.co')
  await chooseRole(dialog, 'Almacenista')
  await userEvent.type(within(dialog).getByLabelText('Contraseña temporal'), 'Temporal123')
  await userEvent.type(within(dialog).getByLabelText('Confirmar contraseña'), 'Temporal123')
}

describe('UserFormDialog (US2, FR-008, FR-009, RN-013)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(false)
  })

  it('crea el usuario con contraseña temporal, normalizado a minúsculas', async () => {
    createUser.mockResolvedValue({ data: { ...existing, id: 10 } })
    const dialog = renderDialog()
    expect(within(dialog).getByText(/deberá cambiar la contraseña temporal/)).toBeInTheDocument()

    await fillCreate(dialog)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear usuario' }))

    expect(createUser).toHaveBeenCalledWith({
      name: 'Juan Pérez',
      username: 'jperez',
      email: 'jperez@bomberos.gov.co',
      role_id: 2,
      password: 'Temporal123',
      password_confirmation: 'Temporal123',
    })
    expect(onSaved).toHaveBeenCalledWith(expect.objectContaining({ id: 10 }), true)
  })

  it('solo ofrece roles activos (US4 escenario 3)', async () => {
    const dialog = renderDialog()

    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Rol' }))

    expect(await screen.findByRole('option', { name: 'Consulta' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Temporal' })).not.toBeInTheDocument()
  })

  it('al editar conserva el rol actual aunque esté inactivo, marcado (RN-017)', async () => {
    const extra = [...roles, role({ id: 6, name: 'Otro inactivo', is_system: false, is_active: false })]
    render(
      <UserFormDialog
        open
        onOpenChange={vi.fn()}
        user={{ ...existing, role: { id: 5, name: 'Temporal', code: null } }}
        roles={extra}
        onSaved={onSaved}
      />
    )

    fireEvent.click(within(screen.getByRole('dialog')).getByRole('combobox', { name: 'Rol' }))

    expect(await screen.findByRole('option', { name: 'Temporal (inactivo)' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Otro inactivo/ })).not.toBeInTheDocument()
  })

  it('rechaza "@" en el nombre de usuario sin llamar al backend', async () => {
    const dialog = renderDialog()

    await fillCreate(dialog, 'a@b')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear usuario' }))

    expect(await within(dialog).findByText('El nombre de usuario no puede contener "@".')).toBeInTheDocument()
    expect(createUser).not.toHaveBeenCalled()
  })

  it('exige el rol', async () => {
    const dialog = renderDialog()

    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Juan Pérez')
    await userEvent.type(within(dialog).getByLabelText('Nombre de usuario'), 'jperez')
    await userEvent.type(within(dialog).getByLabelText('Contraseña temporal'), 'Temporal123')
    await userEvent.type(within(dialog).getByLabelText('Confirmar contraseña'), 'Temporal123')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear usuario' }))

    expect(await within(dialog).findByText('Selecciona un rol.')).toBeInTheDocument()
    expect(createUser).not.toHaveBeenCalled()
  })

  it('muestra el duplicado que reporta el backend en su campo (US2 escenario 2)', async () => {
    createUser.mockRejectedValue(new ApiValidationError('Inválido', { username: ['Ya existe un usuario con ese nombre de usuario.'] }))
    const dialog = renderDialog()

    await fillCreate(dialog)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear usuario' }))

    expect(await within(dialog).findByText('Ya existe un usuario con ese nombre de usuario.')).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
  })

  it('edita sin pedir contraseña', async () => {
    updateUser.mockResolvedValue({ data: existing })
    const dialog = renderDialog(existing)

    expect(within(dialog).getByLabelText('Nombre de usuario')).toHaveValue('jperez')
    expect(within(dialog).queryByLabelText('Contraseña temporal')).not.toBeInTheDocument()
    await chooseRole(dialog, 'Almacenista')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(updateUser).toHaveBeenCalledWith(9, {
      name: 'Juan Pérez',
      username: 'jperez',
      email: 'jperez@bomberos.gov.co',
      role_id: 2,
    })
    expect(onSaved).toHaveBeenCalledWith(existing, false)
  })

  it('muestra el conflicto 409 del último Administrador (FR-012)', async () => {
    updateUser.mockRejectedValue(new ApiError('Debe quedar al menos un Administrador activo.', 409))
    const dialog = renderDialog({ ...existing, role: { id: 1, name: 'Administrador', code: 'admin' } })

    await chooseRole(dialog, 'Consulta')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Debe quedar al menos un Administrador activo.')
  })
})

async function chooseEmployee(dialog: HTMLElement, name: string) {
  fireEvent.click(within(dialog).getByRole('combobox', { name: /Empleado vinculado/ }))
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

describe('UserFormDialog: empleado vinculado (RN-015, 004 D-005)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockImplementation((code: string) => code === 'empleados.ver')
    employeesOptions.mockResolvedValue({
      data: [
        { id: 4, full_name: 'Ana Gómez', position: 'Bombero' },
        { id: 5, full_name: 'Luis Rojas', position: 'Cabo' },
      ],
    })
  })

  it('sin empleados.ver no muestra el select ni envía employee_id', async () => {
    can.mockReturnValue(false)
    createUser.mockResolvedValue({ data: { ...existing, id: 10 } })
    const dialog = renderDialog()

    expect(within(dialog).queryByRole('combobox', { name: /Empleado vinculado/ })).not.toBeInTheDocument()
    expect(employeesOptions).not.toHaveBeenCalled()

    await fillCreate(dialog)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear usuario' }))

    expect(createUser).toHaveBeenCalledTimes(1)
    expect(createUser.mock.calls[0][0]).not.toHaveProperty('employee_id')
  })

  it('sin empleados.ver al editar no toca el vínculo existente', async () => {
    can.mockReturnValue(false)
    updateUser.mockResolvedValue({ data: existing })
    const dialog = renderDialog({ ...existing, employee: { id: 4, full_name: 'Ana Gómez' } })

    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(updateUser.mock.calls[0][1]).not.toHaveProperty('employee_id')
  })

  it('ofrece "Sin vínculo" y los empleados activos, y envía el elegido al crear', async () => {
    createUser.mockResolvedValue({ data: { ...existing, id: 10 } })
    const dialog = renderDialog()

    await fillCreate(dialog)
    fireEvent.click(within(dialog).getByRole('combobox', { name: /Empleado vinculado/ }))
    expect(await screen.findByRole('option', { name: 'Sin vínculo' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Ana Gómez · Bombero' })).toBeInTheDocument()
    const option = screen.getByRole('option', { name: 'Luis Rojas · Cabo' })
    await act(async () => {
      fireEvent.pointerDown(option)
      fireEvent.click(option)
    })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear usuario' }))

    expect(createUser).toHaveBeenCalledWith(expect.objectContaining({ username: 'jperez', employee_id: 5 }))
  })

  it('sin elegir empleado envía employee_id null', async () => {
    createUser.mockResolvedValue({ data: { ...existing, id: 10 } })
    const dialog = renderDialog()

    await fillCreate(dialog)
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear usuario' }))

    expect(createUser.mock.calls[0][0]).toHaveProperty('employee_id', null)
  })

  it('al editar conserva el empleado actual aunque no venga en las opciones, marcado', async () => {
    updateUser.mockResolvedValue({ data: existing })
    const dialog = renderDialog({ ...existing, employee: { id: 7, full_name: 'Pedro Inactivo' } })

    fireEvent.click(within(dialog).getByRole('combobox', { name: /Empleado vinculado/ }))
    expect(await screen.findByRole('option', { name: 'Pedro Inactivo (vínculo actual)' })).toBeInTheDocument()
    fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' })

    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(updateUser).toHaveBeenCalledWith(9, expect.objectContaining({ employee_id: 7 }))
  })

  it('permite desvincular eligiendo "Sin vínculo"', async () => {
    updateUser.mockResolvedValue({ data: existing })
    const dialog = renderDialog({ ...existing, employee: { id: 4, full_name: 'Ana Gómez' } })

    await chooseEmployee(dialog, 'Sin vínculo')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(updateUser).toHaveBeenCalledWith(9, expect.objectContaining({ employee_id: null }))
  })

  it('muestra el error del backend en el campo (empleado ya vinculado)', async () => {
    updateUser.mockRejectedValue(new ApiValidationError('Inválido', { employee_id: ['El empleado ya está vinculado a otro usuario.'] }))
    const dialog = renderDialog(existing)

    await chooseEmployee(dialog, 'Ana Gómez · Bombero')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByText('El empleado ya está vinculado a otro usuario.')).toBeInTheDocument()
  })
})
