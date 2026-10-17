import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { UserRecord } from 'app/features/usuarios/api'
import { ResetPasswordDialog } from './ResetPasswordDialog'

const resetUserPassword = vi.fn()
const onDone = vi.fn()

vi.mock('app/features/usuarios/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/usuarios/api')>()),
  resetUserPassword: (...args: unknown[]) => resetUserPassword(...args),
}))

const user: UserRecord = {
  id: 9,
  name: 'Juan Pérez',
  username: 'jperez',
  email: null,
  is_active: true,
  must_change_password: false,
  last_login_at: null,
  role: { id: 3, name: 'Consulta', code: 'consulta' },
  created_at: '2026-10-17T10:00:00Z',
  locked_until: null,
  employee: null,
}

describe('ResetPasswordDialog (RN-014, FR-009, FR-011)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('advierte el efecto y asigna la contraseña temporal', async () => {
    resetUserPassword.mockResolvedValue({ message: 'ok' })
    render(<ResetPasswordDialog open onOpenChange={vi.fn()} user={user} onDone={onDone} />)
    const dialog = screen.getByRole('dialog', { name: 'Restablecer contraseña' })

    expect(within(dialog).getByText(/se cerrarán sus sesiones abiertas/i)).toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText('Contraseña temporal'), 'Temporal456')
    await userEvent.type(within(dialog).getByLabelText('Confirmar contraseña'), 'Temporal456')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Restablecer' }))

    expect(resetUserPassword).toHaveBeenCalledWith(9, { password: 'Temporal456', password_confirmation: 'Temporal456' })
    expect(onDone).toHaveBeenCalledWith(user)
  })

  it('valida la política antes de enviar', async () => {
    render(<ResetPasswordDialog open onOpenChange={vi.fn()} user={user} onDone={onDone} />)
    const dialog = screen.getByRole('dialog')

    await userEvent.type(within(dialog).getByLabelText('Contraseña temporal'), 'Temporal456')
    await userEvent.type(within(dialog).getByLabelText('Confirmar contraseña'), 'Temporal457')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Restablecer' }))

    expect(await within(dialog).findByText('Las contraseñas no coinciden.')).toBeInTheDocument()
    expect(resetUserPassword).not.toHaveBeenCalled()
  })
})
