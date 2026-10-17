import { todayIsoDate } from 'app/features/empleados/schemas'

const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']

// Fecha ISO (YYYY-MM-DD) → "10 mar 2027", sin pasar por Date (evita el desfase UTC).
export function formatDate(iso: string | null | undefined): string {
  if (!iso) return '—'
  const [year, month, day] = iso.slice(0, 10).split('-')
  const monthName = MONTHS[Number(month) - 1]
  if (!year || !monthName || !day) return iso
  return `${Number(day)} ${monthName} ${year}`
}

const dateTimeFormat = new Intl.DateTimeFormat('es-CO', {
  dateStyle: 'medium',
  timeStyle: 'short',
  timeZone: 'America/Bogota',
})

export function formatDateTime(iso: string): string {
  const date = new Date(iso)
  return Number.isNaN(date.getTime()) ? iso : dateTimeFormat.format(date)
}

const moneyFormat = new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 2 })

export function formatMoney(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  return Number.isFinite(number) ? moneyFormat.format(number) : String(value)
}

function addDays(iso: string, days: number): string {
  const [year, month, day] = iso.split('-').map(Number)
  const date = new Date(Date.UTC(year!, month! - 1, day! + days))
  return date.toISOString().slice(0, 10)
}

export type DueState = 'overdue' | 'soon' | null

// RN-030: "vencido" si la fecha ya pasó; "próximo" si cae dentro de la anticipación.
export function dueState(iso: string | null | undefined, alertDays: number, today: string = todayIsoDate()): DueState {
  if (!iso) return null
  if (iso < today) return 'overdue'
  if (iso <= addDays(today, alertDays)) return 'soon'
  return null
}
