import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { UsuariosScreen } from './UsuariosScreen'

const useRequireAuth = vi.fn()

vi.mock('app/provider/auth', () => ({ useRequireAuth: (...args: unknown[]) => useRequireAuth(...args) }))
vi.mock('./UsersTab', () => ({ UsersTab: () => <p>contenido usuarios</p> }))
vi.mock('./RolesTab', () => ({ RolesTab: () => <p>contenido roles</p> }))
vi.mock('./PermissionMatrix', () => ({ PermissionMatrix: () => <p>contenido matriz</p> }))

describe('UsuariosScreen (/usuarios)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('exige usuarios.ver y muestra las pestañas Usuarios, Roles y Permisos', async () => {
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    render(<UsuariosScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('usuarios.ver')
    expect(screen.getByRole('heading', { name: 'Usuarios y roles' })).toBeInTheDocument()
    expect(screen.getByText('contenido usuarios')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Roles' }))
    expect(await screen.findByText('contenido roles')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Permisos' }))
    expect(await screen.findByText('contenido matriz')).toBeInTheDocument()
  })

  it('sin usuarios.ver muestra "Acceso denegado" (US5 escenario 2)', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<UsuariosScreen />)

    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(screen.queryByRole('tab')).not.toBeInTheDocument()
  })
})
