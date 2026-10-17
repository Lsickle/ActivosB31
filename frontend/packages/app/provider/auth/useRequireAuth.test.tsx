import { renderHook, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuthUser } from '../../features/auth/api'
import { AuthProvider, useRequireAuth } from './index'

const me = vi.fn()
const replace = vi.fn()

vi.mock('solito/navigation', () => ({ useRouter: () => ({ replace, push: vi.fn() }) }))
vi.mock('../../features/auth/api', () => ({
  me: () => me(),
  logout: vi.fn(),
}))

function makeUser(overrides: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 1,
    name: 'Ana Consulta',
    username: 'ana',
    email: 'ana@activosb31.local',
    role: { id: 3, code: 'consulta', name: 'Consulta' },
    permissions: ['activos.ver'],
    must_change_password: false,
    ...overrides,
  }
}

function wrapper({ children }: { children: React.ReactNode }) {
  return <AuthProvider>{children}</AuthProvider>
}

describe('useRequireAuth', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('redirige a /login sin sesión', async () => {
    me.mockRejectedValue(new Error('401'))

    const { result } = renderHook(() => useRequireAuth(), { wrapper })

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/login'))
    expect(result.current.isAuthorized).toBe(false)
  })

  it('autoriza con sesión y el permiso pedido', async () => {
    me.mockResolvedValue({ user: makeUser() })

    const { result } = renderHook(() => useRequireAuth('activos.ver'), { wrapper })

    await waitFor(() => expect(result.current.isAuthorized).toBe(true))
    expect(result.current.isForbidden).toBe(false)
    expect(replace).not.toHaveBeenCalled()
  })

  // US5 escenario 2: sin "Ver" se muestra "Acceso denegado", no una redirección silenciosa.
  it('sin el permiso marca isForbidden y no redirige', async () => {
    me.mockResolvedValue({ user: makeUser() })

    const { result } = renderHook(() => useRequireAuth('usuarios.ver'), { wrapper })

    await waitFor(() => expect(result.current.isForbidden).toBe(true))
    expect(result.current.isAuthorized).toBe(false)
    expect(replace).not.toHaveBeenCalled()
  })

  // RN-014 / FR-009: con contraseña temporal solo se permite cambiarla.
  it('redirige a /cambiar-contrasena si el usuario debe cambiar la contraseña', async () => {
    me.mockResolvedValue({ user: makeUser({ must_change_password: true }) })

    const { result } = renderHook(() => useRequireAuth('activos.ver'), { wrapper })

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/cambiar-contrasena'))
    expect(result.current.isAuthorized).toBe(false)
    expect(result.current.isForbidden).toBe(false)
  })

  it('con allowPendingPasswordChange no redirige a /cambiar-contrasena', async () => {
    me.mockResolvedValue({ user: makeUser({ must_change_password: true }) })

    const { result } = renderHook(() => useRequireAuth(undefined, { allowPendingPasswordChange: true }), { wrapper })

    await waitFor(() => expect(result.current.isAuthorized).toBe(true))
    expect(replace).not.toHaveBeenCalled()
  })
})
