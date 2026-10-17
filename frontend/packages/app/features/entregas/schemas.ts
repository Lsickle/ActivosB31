import { z } from 'zod'
import { todayIsoDate } from '../empleados/schemas'

// Reglas de specs/007-modulo-entregas (FR-001, FR-003; D-002, D-004; RN-023..
// RN-026, RN-038) y del bloque 007 de esquema-bd. El backend valida igual (y
// lo que depende del servidor: empleado activo, activo disponible, existencia
// suficiente, D-003); esto adelanta los mensajes junto al campo.

// ---- Catálogos (RN-023, RN-026, D-005) ----

export const DELIVERY_KINDS = ['dotacion', 'prestamo'] as const

export type DeliveryKind = (typeof DELIVERY_KINDS)[number]

export const DELIVERY_KIND_LABELS: Record<DeliveryKind, string> = {
  dotacion: 'Dotación',
  prestamo: 'Préstamo',
}

export const DELIVERY_STATUSES = ['open', 'closed', 'overdue'] as const

export type DeliveryStatus = (typeof DELIVERY_STATUSES)[number]

export const DELIVERY_STATUS_LABELS: Record<DeliveryStatus, string> = {
  open: 'Abierta',
  closed: 'Cerrada',
  overdue: 'Vencida',
}

export const RETURN_CONDITIONS = ['good', 'damaged', 'lost'] as const

export type ReturnCondition = (typeof RETURN_CONDITIONS)[number]

export const RETURN_CONDITION_LABELS: Record<ReturnCondition, string> = {
  good: 'Bueno',
  damaged: 'Dañado',
  lost: 'Perdido',
}

// ---- Ayudas ----

type Raw = string | number | null | undefined

// Límite técnico de `integer` en PostgreSQL, no una regla de negocio.
const MAX_INT = 2_147_483_647

function parseInteger(value: Raw, min: number): number | null {
  const text = value === null || value === undefined ? '' : String(value).trim()
  if (!/^\d+$/.test(text)) return null
  const number = Number(text)
  return number >= min && number <= MAX_INT ? number : null
}

const QUANTITY_MESSAGE = 'La cantidad debe ser un número entero mayor que 0.'

// deliveries.notes es `text`; el límite evita textos desmedidos (alinear con el backend).
export const NOTES_MAX = 1000

const notesSchema = z
  .string()
  .trim()
  .max(NOTES_MAX, `Las observaciones admiten máximo ${NOTES_MAX} caracteres.`)
  .transform((value) => (value === '' ? null : value))

// ---- Firma (RN-024, D-002) ----

/** D-002: el backend acepta hasta 300 KB decodificados. */
export const MAX_SIGNATURE_BYTES = 300 * 1024

const PNG_PREFIX = 'data:image/png;base64,'
const SIGNATURE_REQUIRED = 'La firma del empleado es obligatoria.'

function decodedBytes(dataUrl: string): number {
  const base64 = dataUrl.slice(PNG_PREFIX.length)
  const padding = base64.endsWith('==') ? 2 : base64.endsWith('=') ? 1 : 0
  return Math.floor((base64.length * 3) / 4) - padding
}

function signatureError(value: string | null | undefined): string | null {
  if (!value || !value.startsWith(PNG_PREFIX) || value.length === PNG_PREFIX.length) return SIGNATURE_REQUIRED
  if (decodedBytes(value) > MAX_SIGNATURE_BYTES) return 'La firma es demasiado grande. Límpiala y firma de nuevo.'
  return null
}

// ---- Nueva entrega (FR-001, D-003) ----

const assetItemSchema = z.object({ asset_id: z.number().int().positive() }).strict()
const quantityItemSchema = z
  .object({
    asset_type_id: z.number().int().positive(),
    quantity: z.union([z.string(), z.number()]),
  })
  .strict()

export type DeliveryItemPayload = { asset_id: number } | { asset_type_id: number; quantity: number }

export type DeliveryPayload = {
  employee_id: number
  kind: DeliveryKind
  /** YYYY-MM-DD; solo en préstamos (RN-023). */
  due_on: string | null
  from_location_id: number
  notes: string | null
  /** Data URL `image/png` (D-002). */
  signature: string
  items: DeliveryItemPayload[]
}

const optionalId = z.number().int().positive().nullable().optional()

// `today` (YYYY-MM-DD) se inyecta para probar el límite; por defecto, hoy local.
// Los obligatorios se revisan en superRefine para que el asistente reciba
// todos los errores a la vez y muestre solo los del paso actual (zod no
// ejecuta los refinamientos del objeto si falla un campo base).
export function deliveryFormSchema(today: string = todayIsoDate()) {
  return z
    .object({
      employee_id: optionalId,
      kind: z.enum(DELIVERY_KINDS).nullable().optional(),
      due_on: z.string().trim().nullable().optional(),
      from_location_id: optionalId,
      notes: notesSchema.nullable().optional(),
      signature: z.string().nullable().optional(),
      items: z.array(z.union([assetItemSchema, quantityItemSchema])),
    })
    .superRefine((value, ctx) => {
      const issue = (path: (string | number)[], message: string) => ctx.addIssue({ code: 'custom', path, message })

      if (!value.employee_id) issue(['employee_id'], 'Selecciona el empleado.')
      if (!value.kind) issue(['kind'], 'Selecciona el tipo de entrega.')
      if (!value.from_location_id) issue(['from_location_id'], 'Selecciona la ubicación de origen.')
      // RN-024 / US1-3: firma obligatoria (D-002: PNG, tamaño máximo).
      const signatureIssue = signatureError(value.signature)
      if (signatureIssue) issue(['signature'], signatureIssue)

      // RN-023 / US1-2: el préstamo exige la fecha esperada de devolución.
      if (value.kind === 'prestamo') {
        const dueOn = value.due_on ?? ''
        if (dueOn === '') issue(['due_on'], 'Indica la fecha de devolución esperada.')
        else if (!z.iso.date().safeParse(dueOn).success) issue(['due_on'], 'Ingresa una fecha válida.')
        else if (dueOn < today) issue(['due_on'], 'La fecha de devolución no puede ser anterior a hoy.')
      }

      // RN-025: uno o más artículos, sin repetir.
      if (value.items.length === 0) {
        issue(['items'], 'Agrega al menos un artículo.')
        return
      }
      const seen = new Set<string>()
      value.items.forEach((item, index) => {
        const key = 'asset_id' in item ? `a${item.asset_id}` : `t${item.asset_type_id}`
        if (seen.has(key)) issue(['items'], 'Hay artículos repetidos.')
        seen.add(key)
        if ('quantity' in item && parseInteger(item.quantity, 1) === null) issue(['items', index, 'quantity'], QUANTITY_MESSAGE)
      })
    })
    .transform(
      (value): DeliveryPayload => ({
        employee_id: value.employee_id as number,
        kind: value.kind as DeliveryKind,
        due_on: value.kind === 'prestamo' ? (value.due_on ?? null) : null,
        from_location_id: value.from_location_id as number,
        notes: value.notes ?? null,
        signature: value.signature as string,
        items: value.items.map((item) =>
          'asset_id' in item ? { asset_id: item.asset_id } : { asset_type_id: item.asset_type_id, quantity: Number(item.quantity) }
        ),
      })
    )
}

// ---- Devolución (FR-003, D-004, RN-026, RN-038) ----

/** Renglón del formulario de devolución (uno por ítem pendiente). */
export type ReturnFormRow = {
  delivery_item_id: number
  /** Activo serializado (cantidad 1). */
  serialized: boolean
  pending_quantity: number
  /** Texto del input; vacío o 0 = no se devuelve en esta ocasión. */
  quantity: Raw
  condition: ReturnCondition | null
  to_location_id: number | null
  notes: string
}

export type ReturnItemPayload = {
  delivery_item_id: number
  quantity: number
  condition: ReturnCondition
  to_location_id?: number
  notes?: string
}

export type ReturnPayload = { items: ReturnItemPayload[] }

// D-004: ¿a dónde vuelve el artículo? Bueno siempre vuelve a una ubicación;
// un serializado dañado queda En mantenimiento en una ubicación; lo perdido
// y lo dañado por cantidad no vuelven al stock (RN-038).
export function returnNeedsLocation(serialized: boolean, condition: ReturnCondition | null): boolean {
  return condition === 'good' || (serialized && condition === 'damaged')
}

const rowSchema = z.object({
  delivery_item_id: z.number().int().positive(),
  serialized: z.boolean(),
  pending_quantity: z.number().int().min(0),
  quantity: z.union([z.string(), z.number(), z.null(), z.undefined()]),
  condition: z.enum(RETURN_CONDITIONS).nullable(),
  to_location_id: z.number().int().positive().nullable(),
  notes: z.string(),
})

function isSkipped(quantity: Raw): boolean {
  const text = quantity === null || quantity === undefined ? '' : String(quantity).trim()
  return text === '' || text === '0'
}

export const returnFormSchema = z
  .object({ items: z.array(rowSchema) })
  .superRefine((value, ctx) => {
    const issue = (path: (string | number)[], message: string) => ctx.addIssue({ code: 'custom', path, message })
    let selected = 0
    value.items.forEach((item, index) => {
      if (isSkipped(item.quantity)) return
      selected += 1
      const quantity = parseInteger(item.quantity, 1)
      if (quantity === null) issue(['items', index, 'quantity'], QUANTITY_MESSAGE)
      else if (quantity > item.pending_quantity) issue(['items', index, 'quantity'], `Máximo ${item.pending_quantity} (pendiente).`)

      if (!item.condition) {
        issue(['items', index, 'condition'], 'Indica el estado.')
        return
      }
      if (returnNeedsLocation(item.serialized, item.condition) && !item.to_location_id) {
        issue(['items', index, 'to_location_id'], 'Selecciona la ubicación a la que vuelve.')
      }
      if (item.condition === 'lost' && item.notes.trim() === '') {
        issue(['items', index, 'notes'], 'Describe qué pasó con el artículo perdido.')
      }
      if (item.notes.trim().length > NOTES_MAX) {
        issue(['items', index, 'notes'], `Las observaciones admiten máximo ${NOTES_MAX} caracteres.`)
      }
    })
    if (selected === 0) issue(['items'], 'Indica al menos un artículo a devolver.')
  })
  .transform(
    (value): ReturnPayload => ({
      items: value.items
        .filter((item) => !isSkipped(item.quantity))
        .map((item) => {
          const condition = item.condition as ReturnCondition
          const payload: ReturnItemPayload = { delivery_item_id: item.delivery_item_id, quantity: Number(item.quantity), condition }
          if (returnNeedsLocation(item.serialized, condition) && item.to_location_id) payload.to_location_id = item.to_location_id
          const notes = item.notes.trim()
          if (notes !== '') payload.notes = notes
          return payload
        }),
    })
  )
