import { z } from 'zod'
import { todayIsoDate } from '../empleados/schemas'

// Reglas de specs/005-modulo-activos (FR-001..FR-005, edge cases; RN-005,
// RN-008, RN-029, RN-030, RN-031) y del bloque 005 de esquema-bd. El backend
// valida igual (y la unicidad de la placa, D-006); esto adelanta los mensajes
// junto al campo. Los campos numéricos aceptan el texto del input (vacío → null).

// ---- Estados (RN-005, D-001) ----

export const ASSET_STATUSES = ['available', 'assigned', 'maintenance', 'retired'] as const

export type AssetStatus = (typeof ASSET_STATUSES)[number]

export const ASSET_STATUS_LABELS: Record<AssetStatus, string> = {
  available: 'Disponible',
  assigned: 'Asignado',
  maintenance: 'En mantenimiento',
  retired: 'Dado de baja',
}

// Estados que se pueden poner a mano (FR-003); `assigned` solo lo pone Entregas (007).
export const MANUAL_STATUSES = ['available', 'maintenance', 'retired'] as const

export type ManualStatus = (typeof MANUAL_STATUSES)[number]

// D-001: Disponible ⇄ En mantenimiento; Disponible / En mantenimiento → Dado de
// baja (definitivo). Asignado y Dado de baja no admiten cambios manuales.
export function manualTransitions(status: AssetStatus): ManualStatus[] {
  switch (status) {
    case 'available':
      return ['maintenance', 'retired']
    case 'maintenance':
      return ['available', 'retired']
    default:
      return []
  }
}

// ---- Ayudas de campos ----

type Raw = string | number | null | undefined

function optionalText(max: number, message: string) {
  return z
    .string()
    .trim()
    .max(max, message)
    .transform((value) => (value === '' ? null : value))
}

function requiredId(message: string) {
  return z.number({ error: message }).int().positive(message)
}

function rawText(value: Raw): string {
  return value === null || value === undefined ? '' : String(value).trim()
}

// Decimal opcional ≥ 0 con hasta 2 decimales (numeric(14,2) en el esquema).
const MAX_MONEY = 999_999_999_999.99

function optionalMoney(negativeMessage: string) {
  return z.union([z.string(), z.number(), z.null()]).transform((value, ctx): number | null => {
    const text = rawText(value)
    if (text === '') return null
    const number = Number(text)
    if (!Number.isFinite(number)) {
      ctx.addIssue({ code: 'custom', message: 'Ingresa un valor numérico.' })
      return z.NEVER
    }
    if (number < 0) {
      ctx.addIssue({ code: 'custom', message: negativeMessage })
      return z.NEVER
    }
    if (number > MAX_MONEY || !/^\d+(\.\d{1,2})?$/.test(text)) {
      ctx.addIssue({ code: 'custom', message: 'Ingresa un valor con máximo dos decimales.' })
      return z.NEVER
    }
    return number
  })
}

// Entero en [min, max]; `fallback` = valor cuando el campo llega vacío
// (undefined = el campo es obligatorio, null = opcional).
function integerField(min: number, max: number, message: string, fallback?: number | null) {
  return z.union([z.string(), z.number(), z.null(), z.undefined()]).transform((value, ctx): number | null => {
    const text = rawText(value)
    if (text === '') {
      if (fallback !== undefined) return fallback
      ctx.addIssue({ code: 'custom', message })
      return z.NEVER
    }
    const number = Number(text)
    if (!Number.isInteger(number) || number < min || number > max) {
      ctx.addIssue({ code: 'custom', message })
      return z.NEVER
    }
    return number
  })
}

// Entero obligatorio (o con valor por defecto): el resultado nunca es null.
function requiredInteger(min: number, max: number, message: string, fallback?: number) {
  return integerField(min, max, message, fallback).transform((value) => value as number)
}

function optionalDate() {
  return z
    .string()
    .trim()
    .transform((value) => (value === '' ? null : value))
    .pipe(z.iso.date('Ingresa una fecha válida.').nullable())
}

function optionalPastDate(futureMessage: string) {
  return optionalDate().refine((value) => value === null || value <= todayIsoDate(), futureMessage)
}

// ---- Activo (FR-001, FR-002, RN-008) ----

// D-006: la placa se recorta y se guarda en mayúsculas (única sin distinguir mayúsculas).
const plateSchema = z
  .string()
  .transform((value) => value.trim().toUpperCase())
  .pipe(z.string().min(1, 'Ingresa la placa de inventario.').max(50, 'La placa admite máximo 50 caracteres.'))

export const assetFormSchema = z.object({
  // D-007: solo tipos serializados, activos y de categoría activa (lo filtra el formulario).
  asset_type_id: requiredId('Selecciona el tipo de activo.'),
  plate: plateSchema,
  serial: optionalText(100, 'El serial admite máximo 100 caracteres.'),
  brand: optionalText(80, 'La marca admite máximo 80 caracteres.'),
  model: optionalText(80, 'El modelo admite máximo 80 caracteres.'),
  acquisition_date: optionalPastDate('La fecha de adquisición no puede ser futura.'),
  acquisition_value: optionalMoney('El valor no puede ser negativo.'),
  // smallint en el esquema.
  useful_life_months: integerField(1, 32767, 'La vida útil debe ser un número entero de meses mayor que 0.', null),
  // RN-031: vencimiento opcional; puede (y suele) ser futuro.
  expires_on: optionalDate(),
  // assets.location_id es NULL en el esquema: la ubicación es opcional.
  location_id: z.number().int().positive().nullable(),
  notes: optionalText(1000, 'Las observaciones admiten máximo 1000 caracteres.'),
})

export type AssetFormValues = z.output<typeof assetFormSchema>

// Al editar no se envían ubicación ni estado: se cambian con sus propias
// acciones (PATCH /location, /status), que dejan su evento en el historial (D-002).
export const assetUpdateSchema = assetFormSchema.omit({ location_id: true })

export type AssetUpdateValues = z.output<typeof assetUpdateSchema>

// ---- Foto (FR-008, edge case) ----

export const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const

// 4 MB: el proxy de Vercel corta el cuerpo de la petición en ~4,5 MB (M-1 de
// la revisión de seguridad de 005); el backend valida lo mismo (AssetPhotoRules).
export const MAX_PHOTO_BYTES = 4 * 1024 * 1024

// Sin depender de `File` del DOM (packages/app sirve también a una futura app móvil).
export function photoError(file: { type: string; size: number }): string | null {
  if (!(PHOTO_TYPES as readonly string[]).includes(file.type)) return 'La foto debe ser JPEG, PNG o WebP.'
  if (file.size > MAX_PHOTO_BYTES) return 'La foto no puede superar 4 MB.'
  return null
}

// ---- Estado y ubicación (D-001) ----

export const statusChangeSchema = z
  .object({
    status: z.enum(MANUAL_STATUSES, { error: 'Selecciona el nuevo estado.' }),
    reason: optionalText(500, 'El motivo admite máximo 500 caracteres.'),
  })
  .superRefine((value, ctx) => {
    if (value.status === 'retired' && value.reason === null) {
      ctx.addIssue({ code: 'custom', path: ['reason'], message: 'Indica el motivo de la baja.' })
    }
  })

export type StatusChangeValues = z.output<typeof statusChangeSchema>

export const locationChangeSchema = z.object({
  location_id: requiredId('Selecciona la nueva ubicación.'),
})

// ---- Mantenimientos (RN-029, RN-030, D-003) ----

export const DEFAULT_ALERT_DAYS = 30

const ALERT_DAYS_MESSAGE = 'La anticipación debe ser un número entero de días entre 0 y 365.'

export const maintenancePlanSchema = z.object({
  name: z.string().trim().min(1, 'Ingresa el nombre del mantenimiento.').max(120, 'El nombre admite máximo 120 caracteres.'),
  interval_months: requiredInteger(1, 600, 'La periodicidad debe ser un número entero de meses entre 1 y 600.'),
  last_done_on: z
    .union([z.string(), z.null(), z.undefined()])
    .transform((value) => value ?? '')
    .pipe(optionalPastDate('La fecha del último realizado no puede ser futura.')),
  alert_days: requiredInteger(0, 365, ALERT_DAYS_MESSAGE, DEFAULT_ALERT_DAYS),
  is_active: z.boolean().default(true),
})

export type MaintenancePlanValues = z.output<typeof maintenancePlanSchema>

export const maintenanceRecordSchema = z.object({
  plan_id: z.number().int().positive().nullable(),
  performed_on: z
    .string()
    .trim()
    .min(1, 'Ingresa la fecha del mantenimiento.')
    .pipe(z.iso.date('Ingresa una fecha válida.'))
    .refine((value) => value <= todayIsoDate(), 'La fecha del mantenimiento no puede ser futura.'),
  description: z.string().trim().min(1, 'Describe el mantenimiento realizado.').max(500, 'La descripción admite máximo 500 caracteres.'),
  performed_by: optionalText(120, 'Quién lo hizo admite máximo 120 caracteres.'),
  cost: optionalMoney('El costo no puede ser negativo.'),
})

export type MaintenanceRecordValues = z.output<typeof maintenanceRecordSchema>

// ---- Ajustes (D-005, RN-030) ----

export const expirySettingsSchema = z.object({
  expiry_alert_days: requiredInteger(0, 365, ALERT_DAYS_MESSAGE),
})

export type ExpirySettingsValues = z.output<typeof expirySettingsSchema>
