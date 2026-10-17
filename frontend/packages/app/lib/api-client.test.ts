import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  ApiValidationError,
  PASSWORD_CHANGE_REQUIRED,
  RateLimitError,
  apiFetch,
  onAuthError,
} from './api-client'

function jsonResponse(status: number, body: unknown, headers: Record<string, string> = {}) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } })
}

describe('apiFetch', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
  })

  it('pide la cookie CSRF y reenvía el token en X-XSRF-TOKEN', async () => {
    document.cookie = 'XSRF-TOKEN=abc%3D'
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(jsonResponse(200, { ok: true }))
    vi.stubGlobal('fetch', fetchMock)

    await expect(apiFetch('/api/user')).resolves.toEqual({ ok: true })

    expect(fetchMock.mock.calls[0]![0]).toMatch(/\/sanctum\/csrf-cookie$/)
    const [, init] = fetchMock.mock.calls[1]!
    expect(init.credentials).toBe('include')
    expect(init.headers['X-XSRF-TOKEN']).toBe('abc=')
  })

  it('convierte un 422 en ApiValidationError', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(
        jsonResponse(422, { message: 'Inválido', errors: { login: ['Credenciales inválidas.'] } })
      )
    )

    const error = await apiFetch('/api/login').catch((e) => e)

    expect(error).toBeInstanceOf(ApiValidationError)
    expect(error.firstError('login')).toBe('Credenciales inválidas.')
  })

  it('convierte un 429 en RateLimitError con Retry-After', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(jsonResponse(429, {}, { 'Retry-After': '42' }))
    )

    const error = await apiFetch('/api/login').catch((e) => e)

    expect(error).toBeInstanceOf(RateLimitError)
    expect(error.retryAfterSeconds).toBe(42)
  })

  it('lanza ApiError con status, code y el mensaje del backend', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(
        jsonResponse(409, { message: 'Debe quedar al menos un Administrador activo.' })
      )
    )

    const error = await apiFetch('/api/users/1/status').catch((e) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toBeInstanceOf(Error)
    expect(error.status).toBe(409)
    expect(error.code).toBeUndefined()
    expect(error.message).toBe('Debe quedar al menos un Administrador activo.')
  })

  // RN-014 / D-004: el backend bloquea todo salvo el cambio de contraseña.
  it('avisa al listener de auth con un 403 password_change_required', async () => {
    const listener = vi.fn()
    const unsubscribe = onAuthError(listener)
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(
        jsonResponse(403, { message: 'Debes cambiar tu contraseña.', code: 'password_change_required' })
      )
    )

    const error = await apiFetch('/api/users').catch((e) => e)
    unsubscribe()

    expect(error).toBeInstanceOf(ApiError)
    expect(error.code).toBe(PASSWORD_CHANGE_REQUIRED)
    expect(listener).toHaveBeenCalledWith('password_change_required')
  })

  it('avisa al listener de auth con un 401 (sesión revocada o vencida)', async () => {
    const listener = vi.fn()
    const unsubscribe = onAuthError(listener)
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(jsonResponse(401, { message: 'Unauthenticated.' }))
    )

    await apiFetch('/api/users').catch(() => undefined)
    unsubscribe()

    expect(listener).toHaveBeenCalledWith('unauthenticated')
  })

  // B-7: permiso retirado a mitad de sesión: el provider recarga /api/user.
  it('avisa "forbidden" al listener con un 403 sin code', async () => {
    const listener = vi.fn()
    const unsubscribe = onAuthError(listener)
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(jsonResponse(403, { message: 'This action is unauthorized.' }))
    )

    const error = await apiFetch('/api/users').catch((e) => e)
    unsubscribe()

    expect(error.status).toBe(403)
    expect(listener).toHaveBeenCalledWith('forbidden')
    expect(listener).not.toHaveBeenCalledWith('password_change_required')
  })
})
