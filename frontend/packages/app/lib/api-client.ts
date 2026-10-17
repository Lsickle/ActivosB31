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
    throw new Error(body?.message ?? `Error inesperado (${response.status}).`)
  }

  return body as T
}
