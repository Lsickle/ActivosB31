// Utilidades comunes a los clientes de API de cada módulo (features/<modulo>/api.ts).

export type PaginationMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

// Formato de los listados paginados de Laravel (`{data, meta, links}`).
export type Paginated<T> = { data: T[]; meta: PaginationMeta }

export type QueryValue = string | number | boolean | undefined | null

// Laravel valida `boolean` con 1/0: no acepta "true"/"false" en query string.
// Se omiten los valores vacíos para no enviar filtros sin efecto.
export function query(params: Record<string, QueryValue>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    search.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
  }
  const result = search.toString()
  return result ? `?${result}` : ''
}

export function jsonBody(method: string, body: unknown): RequestInit {
  return { method, body: JSON.stringify(body) }
}
