import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ApiError,
  ApiValidationError,
  PASSWORD_CHANGE_REQUIRED,
  RateLimitError,
  apiDownload,
  apiFetch,
  filenameFromContentDisposition,
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

  it('con FormData (multipart, 005 fotos) no fija Content-Type y conserva CSRF y credenciales', async () => {
    document.cookie = 'XSRF-TOKEN=tok'
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(jsonResponse(201, { data: {} }))
    vi.stubGlobal('fetch', fetchMock)
    const body = new FormData()
    body.append('plate', 'B31-0001')

    await apiFetch('/api/assets', { method: 'POST', body })

    const [, init] = fetchMock.mock.calls[1]!
    expect(init.body).toBe(body)
    expect(init.credentials).toBe('include')
    expect(init.headers['Content-Type']).toBeUndefined()
    expect(init.headers.Accept).toBe('application/json')
    expect(init.headers['X-XSRF-TOKEN']).toBe('tok')
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

// 008 D-002 (RN-032): descarga de reportes en Excel/PDF con la misma sesión y CSRF.
describe('apiDownload', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT'
  })

  it('pide el archivo con credenciales y X-XSRF-TOKEN y devuelve Blob + nombre de Content-Disposition', async () => {
    document.cookie = 'XSRF-TOKEN=tok'
    const fileResponse = new Response('xlsx-bytes', {
      status: 200,
      headers: {
        'Content-Type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'Content-Disposition': 'attachment; filename="inventario-2026-10-17.xlsx"',
      },
    })
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(fileResponse)
    vi.stubGlobal('fetch', fetchMock)

    const file = await apiDownload('/api/reports/inventory?format=xlsx')

    expect(fetchMock.mock.calls[0]![0]).toMatch(/\/sanctum\/csrf-cookie$/)
    const [url, init] = fetchMock.mock.calls[1]!
    expect(url).toMatch(/\/api\/reports\/inventory\?format=xlsx$/)
    expect(init.credentials).toBe('include')
    expect(init.headers['X-XSRF-TOKEN']).toBe('tok')
    expect(init.headers.Accept).toBe('application/json')
    expect(file.filename).toBe('inventario-2026-10-17.xlsx')
    expect(await file.blob.text()).toBe('xlsx-bytes')
  })

  it('sin Content-Disposition devuelve filename null', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValueOnce(new Response(null, { status: 204 })).mockResolvedValueOnce(new Response('pdf', { status: 200 }))
    )

    const file = await apiDownload('/api/reports/inventory?format=pdf')

    expect(file.filename).toBeNull()
  })

  it('un error responde igual que apiFetch: 422 → ApiValidationError, 403 → ApiError y aviso al listener', async () => {
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValueOnce(new Response(null, { status: 204 }))
        .mockResolvedValueOnce(jsonResponse(422, { message: 'Inválido', errors: { to: ['Rango inválido.'] } }))
        .mockResolvedValueOnce(new Response(null, { status: 204 }))
        .mockResolvedValueOnce(jsonResponse(403, { message: 'Acceso denegado.' }))
    )
    const listener = vi.fn()
    const unsubscribe = onAuthError(listener)

    const validation = await apiDownload('/api/reports/employees/1?format=pdf').catch((e) => e)
    expect(validation).toBeInstanceOf(ApiValidationError)
    expect(validation.firstError('to')).toBe('Rango inválido.')

    const forbidden = await apiDownload('/api/reports/employees/1?format=pdf').catch((e) => e)
    expect(forbidden).toBeInstanceOf(ApiError)
    expect(forbidden.status).toBe(403)
    expect(forbidden.message).toBe('Acceso denegado.')
    expect(listener).toHaveBeenCalledWith('forbidden')
    unsubscribe()
  })
})

describe('filenameFromContentDisposition', () => {
  it('lee filename entre comillas, sin comillas y filename* (RFC 5987, prioridad)', () => {
    expect(filenameFromContentDisposition('attachment; filename="reporte.pdf"')).toBe('reporte.pdf')
    expect(filenameFromContentDisposition('attachment; filename=reporte.xlsx')).toBe('reporte.xlsx')
    expect(
      filenameFromContentDisposition(`attachment; filename="empleado.pdf"; filename*=UTF-8''empleado-P%C3%A9rez-2026-10-17.pdf`)
    ).toBe('empleado-Pérez-2026-10-17.pdf')
  })

  it('descarta rutas y devuelve null si no hay nombre', () => {
    expect(filenameFromContentDisposition('attachment; filename="../../etc/passwd"')).toBe('passwd')
    expect(filenameFromContentDisposition('attachment; filename="C:\\\\temp\\\\reporte.pdf"')).toBe('reporte.pdf')
    expect(filenameFromContentDisposition('attachment')).toBeNull()
    expect(filenameFromContentDisposition(null)).toBeNull()
  })
})
