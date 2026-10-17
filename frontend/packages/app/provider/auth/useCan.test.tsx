import { act, render, renderHook, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AuthUser } from '../../features/auth/api'
import { apiFetch } from '../../lib/api-client'
import { AuthProvider, useAuth, useCan } from './index'

const me = vi.fn()

vi.mock('solito/navigation', () => ({ useRouter: () => ({ replace: vi.fn(), push: vi.fn() }) }))
vi.mock('../../features/auth/api', () => ({
  me: () => me(),
  logout: vi.fn(),
}))

function makeUser(overrides: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 1,
    name: 'Ana Consulta',
    username: 'ana',
    email: null,
    role: { id: 3, code: 'consulta', name: 'Consulta' },
    permissions: ['activos.ver', 'usuarios.ver'],
    must_change_password: false,
    ...overrides,
  }
}

function wrapper({ children }: { children: React.ReactNode }) {
  return <AuthProvider>{children}</AuthProvider>
}

describe('useCan (RN-009)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('es true solo si el permiso está en los permisos efectivos del usuario', async () => {
    me.mockResolvedValue({ user: makeUser() })

    const { result } = renderHook(() => ({ ver: useCan('usuarios.ver'), gestionar: useCan('usuarios.gestionar') }), { wrapper })

    await waitFor(() => expect(result.current.ver).toBe(true))
    expect(result.current.gestionar).toBe(false)
  })

  it('es false sin sesión', async () => {
    me.mockRejectedValue(new Error('401'))

    const { result } = renderHook(() => useCan('usuarios.ver'), { wrapper })

    await waitFor(() => expect(me).toHaveBeenCalled())
    expect(result.current).toBe(false)
  })
})

describe('AuthProvider ante errores de auth de la API', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function UserName() {
    const { user } = useAuth()
    return <p>{user ? `${user.name}${user.must_change_password ? ' (cambio pendiente)' : ''}` : 'sin sesión'}</p>
  }

  it('cierra la sesión local con un 401 (FR-011: sesión revocada)', async () => {
    me.mockResolvedValue({ user: makeUser() })
    render(<UserName />, { wrapper })
    expect(await screen.findByText('Ana Consulta')).toBeInTheDocument()

    // Notifica como lo haría apiFetch ante un 401.
    await act(async () => {
      notify('unauthenticated')
    })

    expect(await screen.findByText('sin sesión')).toBeInTheDocument()
  })

  it('recarga el usuario con un 403 password_change_required (RN-014)', async () => {
    me.mockResolvedValueOnce({ user: makeUser() }).mockResolvedValueOnce({ user: makeUser({ must_change_password: true }) })
    render(<UserName />, { wrapper })
    expect(await screen.findByText('Ana Consulta')).toBeInTheDocument()

    await act(async () => {
      notify('password_change_required')
    })

    expect(await screen.findByText('Ana Consulta (cambio pendiente)')).toBeInTheDocument()
  })
})

describe('AuthProvider ante un 403 sin code (B-7)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('recarga el usuario para actualizar menú y botones', async () => {
    me.mockResolvedValueOnce({ user: makeUser() }).mockResolvedValueOnce({ user: makeUser({ permissions: ['activos.ver'] }) })
    const { result } = renderHook(() => useCan('usuarios.ver'), { wrapper })
    await waitFor(() => expect(result.current).toBe(true))

    await act(async () => {
      await notify('forbidden')
    })

    await waitFor(() => expect(result.current).toBe(false))
    expect(me).toHaveBeenCalledTimes(2)
  })
})

// Simula la respuesta real de otra pantalla: apiFetch avisa a los listeners
// que registró el AuthProvider.
async function notify(kind: 'unauthenticated' | 'password_change_required' | 'forbidden') {
  const body =
    kind === 'unauthenticated'
      ? { message: 'Unauthenticated.' }
      : kind === 'forbidden'
        ? { message: 'This action is unauthorized.' }
        : { message: 'Debes cambiar tu contraseña.', code: kind }
  vi.stubGlobal(
    'fetch',
    vi
      .fn()
      .mockResolvedValueOnce(new Response(null, { status: 204 }))
      .mockResolvedValueOnce(new Response(JSON.stringify(body), { status: kind === 'unauthenticated' ? 401 : 403 }))
  )
  await apiFetch('/api/users').catch(() => undefined)
  vi.unstubAllGlobals()
}
