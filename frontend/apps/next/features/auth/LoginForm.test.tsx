import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError } from 'app/lib/api-client'
import { LoginForm } from './LoginForm'

const replace = vi.fn()
const refresh = vi.fn()
const login = vi.fn()

vi.mock('next/navigation', () => ({ useRouter: () => ({ replace }) }))
vi.mock('app/provider/auth', () => ({ useAuth: () => ({ refresh }) }))
vi.mock('app/features/auth/api', () => ({ login: (...args: unknown[]) => login(...args) }))

describe('LoginForm', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('valida campos vacíos sin llamar al backend', async () => {
    render(<LoginForm />)

    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Ingresa tu usuario o correo.')
    expect(login).not.toHaveBeenCalled()
  })

  it('inicia sesión y redirige al inicio', async () => {
    login.mockResolvedValue({ user: {} })
    render(<LoginForm />)

    await userEvent.type(screen.getByLabelText('Usuario o correo'), 'admin')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'secreto')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(login).toHaveBeenCalledWith({ login: 'admin', password: 'secreto' })
    expect(refresh).toHaveBeenCalled()
    expect(replace).toHaveBeenCalledWith('/')
  })

  // RN-014 / FR-009: con contraseña temporal solo puede cambiarla.
  it('lleva a /cambiar-contrasena si el usuario debe cambiar la contraseña', async () => {
    login.mockResolvedValue({ user: { must_change_password: true } })
    render(<LoginForm />)

    await userEvent.type(screen.getByLabelText('Usuario o correo'), 'consulta1')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'Temporal123')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(replace).toHaveBeenCalledWith('/cambiar-contrasena')
    expect(replace).not.toHaveBeenCalledWith('/')
  })

  it('muestra el error de credenciales del backend', async () => {
    login.mockRejectedValue(new ApiValidationError('Inválido', { login: ['Credenciales inválidas.'] }))
    render(<LoginForm />)

    await userEvent.type(screen.getByLabelText('Usuario o correo'), 'admin')
    await userEvent.type(screen.getByLabelText('Contraseña'), 'mala')
    await userEvent.click(screen.getByRole('button', { name: 'Ingresar' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Credenciales inválidas.')
    expect(replace).not.toHaveBeenCalled()
  })
})
