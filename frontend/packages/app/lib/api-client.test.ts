import { afterEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError, RateLimitError, apiFetch } from './api-client'

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
})
