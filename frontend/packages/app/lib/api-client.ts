// Cliente HTTP compartido para el backend Laravel (Sanctum SPA): cookie de
// sesión + ciclo CSRF antes de cada request, header X-XSRF-TOKEN leído de la
// cookie. Todos los módulos lo reutilizan en vez de llamar fetch() directo.
// En producción (Vercel) el valor por defecto es '' = mismo origen, servido
// por el proxy de next.config.js hacia BACKEND_URL.
const API_URL =
  process.env.NEXT_PUBLIC_API_URL ?? (process.env.NODE_ENV === 'production' ? '' : 'http://localhost:8081')

export function apiUrl(path: string): string {
  return `${API_URL}${path}`
}

export class ApiValidationError extends Error {
  constructor(
    message: string,
    public readonly errors: Record<string, string[]>
  ) {
    super(message)
    this.name = 'ApiValidationError'
  }

  firstError(field: string): string | undefined {
    return this.errors[field]?.[0]
  }
}

// Cualquier otra respuesta no-OK. Sigue siendo un Error con el `message` del
// backend (compatible con los `catch` que solo leen `err.message`), y además
// expone `status` (p. ej. 409 de invariantes) y el `code` opcional del body.
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code?: string
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

// D-004 (RN-014): 403 que el middleware `password.changed` devuelve mientras
// el usuario tenga la contraseña temporal pendiente de cambio.
export const PASSWORD_CHANGE_REQUIRED = 'password_change_required'

// 'forbidden' = 403 sin code: p. ej. un permiso retirado a mitad de sesión (B-7).
export type AuthErrorKind = 'unauthenticated' | 'password_change_required' | 'forbidden'

// El cliente no conoce el router ni el estado de sesión: el AuthProvider se
// suscribe aquí para reaccionar (volver al login, ir a /cambiar-contrasena).
const authErrorListeners = new Set<(kind: AuthErrorKind) => void>()

export function onAuthError(listener: (kind: AuthErrorKind) => void): () => void {
  authErrorListeners.add(listener)
  return () => {
    authErrorListeners.delete(listener)
  }
}

function notifyAuthError(kind: AuthErrorKind): void {
  authErrorListeners.forEach((listener) => listener(kind))
}

const DEFAULT_RETRY_AFTER_SECONDS = 60

export class RateLimitError extends Error {
  constructor(public readonly retryAfterSeconds: number) {
    super(`Demasiados intentos. Intenta de nuevo en ${retryAfterSeconds} segundos.`)
    this.name = 'RateLimitError'
  }
}

function readCookie(name: string): string | null {
  const match = document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${name}=`))
  return match ? decodeURIComponent(match.split('=').slice(1).join('=')) : null
}

// Comparte la petición en vuelo entre llamadas concurrentes para no pedir
// /sanctum/csrf-cookie varias veces a la vez.
let inFlightCsrfCookie: Promise<void> | null = null

async function ensureCsrfCookie(): Promise<void> {
  if (!inFlightCsrfCookie) {
    inFlightCsrfCookie = fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: 'include' })
      .then(() => undefined)
      .finally(() => {
        inFlightCsrfCookie = null
      })
  }

  return inFlightCsrfCookie
}

export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  await ensureCsrfCookie()
  const xsrfToken = readCookie('XSRF-TOKEN')

  // Con FormData el navegador fija su propio Content-Type multipart.
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData

  const response = await fetch(`${API_URL}${path}`, {
    ...options,
    credentials: 'include',
    headers: {
      ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
      Accept: 'application/json',
      ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
      ...options.headers,
    },
  })

  const body = await response.json().catch(() => null)

  if (response.status === 422 && body?.errors) {
    throw new ApiValidationError(body.message ?? 'Error de validación.', body.errors)
  }

  if (response.status === 429) {
    const retryAfterHeader = response.headers.get('Retry-After')
    const retryAfterSeconds = retryAfterHeader ? Number.parseInt(retryAfterHeader, 10) : NaN
    throw new RateLimitError(Number.isFinite(retryAfterSeconds) ? retryAfterSeconds : DEFAULT_RETRY_AFTER_SECONDS)
  }

  if (!response.ok) {
    const code = typeof body?.code === 'string' ? body.code : undefined
    if (response.status === 401) {
      notifyAuthError('unauthenticated')
    } else if (response.status === 403) {
      notifyAuthError(code === PASSWORD_CHANGE_REQUIRED ? 'password_change_required' : 'forbidden')
    }
    throw new ApiError(body?.message ?? `Error inesperado (${response.status}).`, response.status, code)
  }

  return body as T
}
