import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError } from 'app/lib/api-client'
import { ChangePasswordForm } from './ChangePasswordForm'

const replace = vi.fn()
const push = vi.fn()
const refresh = vi.fn()
const logout = vi.fn()
const changePassword = vi.fn()
const toastSuccess = vi.fn()
const useRequireAuth = vi.fn()

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace, push }) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))
vi.mock('app/provider/auth', () => ({
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))
vi.mock('app/features/auth/api', () => ({ changePassword: (...args: unknown[]) => changePassword(...args) }))

function authState(mustChangePassword: boolean) {
  return {
    user: { id: 5, name: 'Consulta Uno', must_change_password: mustChangePassword, permissions: [] },
    isLoading: false,
    isAuthorized: true,
    isForbidden: false,
    refresh,
    logout,
  }
}

async function fill(current: string, password: string, confirmation = password) {
  await userEvent.type(screen.getByLabelText(/Contraseña actual/), current)
  await userEvent.type(screen.getByLabelText('Nueva contraseña'), password)
  await userEvent.type(screen.getByLabelText('Confirmar nueva contraseña'), confirmation)
  await userEvent.click(screen.getByRole('button', { name: 'Cambiar contraseña' }))
}

describe('ChangePasswordForm (RN-014, FR-009, FR-010)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue(authState(true))
  })

  it('exige sesión pero permite entrar con el cambio pendiente', () => {
    render(<ChangePasswordForm />)

    expect(useRequireAuth).toHaveBeenCalledWith(undefined, { allowPendingPasswordChange: true })
    expect(screen.getByText(/Debes cambiar tu contraseña temporal/)).toBeInTheDocument()
  })

  it('no muestra el formulario sin sesión', () => {
    useRequireAuth.mockReturnValue({ ...authState(false), user: null, isAuthorized: false })
    render(<ChangePasswordForm />)

    expect(screen.queryByLabelText('Nueva contraseña')).not.toBeInTheDocument()
  })

  it('valida la política de contraseña sin llamar al backend', async () => {
    render(<ChangePasswordForm />)

    await fill('Temporal123', 'corta1')

    expect(await screen.findByText('La contraseña debe tener al menos 10 caracteres.')).toBeInTheDocument()
    expect(changePassword).not.toHaveBeenCalled()
  })

  it('rechaza repetir la contraseña actual', async () => {
    render(<ChangePasswordForm />)

    await fill('Temporal123', 'Temporal123')

    expect(await screen.findByText('La nueva contraseña debe ser distinta de la actual.')).toBeInTheDocument()
    expect(changePassword).not.toHaveBeenCalled()
  })

  it('rechaza una confirmación distinta', async () => {
    render(<ChangePasswordForm />)

    await fill('Temporal123', 'NuevaClave2026', 'NuevaClave2027')

    expect(await screen.findByText('Las contraseñas no coinciden.')).toBeInTheDocument()
    expect(changePassword).not.toHaveBeenCalled()
  })

  it('cambia la contraseña, recarga la sesión y lleva al tablero', async () => {
    changePassword.mockResolvedValue({ message: 'Contraseña actualizada.' })
    render(<ChangePasswordForm />)

    await fill('Temporal123', 'NuevaClave2026')

    expect(changePassword).toHaveBeenCalledWith({
      current_password: 'Temporal123',
      password: 'NuevaClave2026',
      password_confirmation: 'NuevaClave2026',
    })
    expect(refresh).toHaveBeenCalled()
    expect(toastSuccess).toHaveBeenCalledWith('Contraseña actualizada.')
    expect(replace).toHaveBeenCalledWith('/')
  })

  it('muestra los errores 422 del backend en su campo', async () => {
    changePassword.mockRejectedValue(
      new ApiValidationError('Inválido', { current_password: ['La contraseña actual no es correcta.'] })
    )
    render(<ChangePasswordForm />)

    await fill('Equivocada1', 'NuevaClave2026')

    expect(await screen.findByText('La contraseña actual no es correcta.')).toBeInTheDocument()
    expect(replace).not.toHaveBeenCalled()
  })

  it('con el cambio pendiente ofrece cerrar sesión', async () => {
    logout.mockResolvedValue(undefined)
    render(<ChangePasswordForm />)

    await userEvent.click(screen.getByRole('button', { name: 'Cerrar sesión' }))

    expect(logout).toHaveBeenCalled()
    expect(push).toHaveBeenCalledWith('/login')
  })

  it('sin cambio pendiente (cambio voluntario) ofrece volver', async () => {
    useRequireAuth.mockReturnValue(authState(false))
    render(<ChangePasswordForm />)

    expect(screen.queryByText(/Debes cambiar tu contraseña temporal/)).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }))

    expect(push).toHaveBeenCalledWith('/')
  })
})
