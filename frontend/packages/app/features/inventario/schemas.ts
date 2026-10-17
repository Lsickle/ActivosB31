import { z } from 'zod'

// Reglas de specs/006-modulo-inventario (FR-001, FR-002, FR-004; D-002, D-004;
// RN-007, RN-027). El backend valida igual (y la existencia suficiente, D-001);
// esto adelanta los mensajes junto al campo. Los campos numéricos aceptan el
// texto del input.

// ---- Tipos de movimiento (D-002) ----

export const MOVEMENT_TYPES = ['entry', 'exit', 'transfer', 'adjustment', 'delivery', 'return', 'loss'] as const

export type MovementType = (typeof MOVEMENT_TYPES)[number]

export const MOVEMENT_TYPE_LABELS: Record<MovementType, string> = {
  entry: 'Entrada',
  exit: 'Salida',
  transfer: 'Traslado',
  adjustment: 'Ajuste',
  delivery: 'Entrega',
  return: 'Devolución',
  // RN-038: lo devuelto dañado o perdido no vuelve al stock.
  loss: 'Pérdida',
}

// Entrega, devolución y pérdida solo las genera Entregas (007).
export const MANUAL_MOVEMENT_TYPES = ['entry', 'exit', 'transfer', 'adjustment'] as const

export type ManualMovementType = (typeof MANUAL_MOVEMENT_TYPES)[number]

export const ADJUSTMENT_DIRECTIONS = ['increase', 'decrease'] as const

export type AdjustmentDirection = (typeof ADJUSTMENT_DIRECTIONS)[number]

export const ADJUSTMENT_DIRECTION_LABELS: Record<AdjustmentDirection, string> = {
  increase: 'Aumentar',
  decrease: 'Disminuir',
}

export const COUNT_STATUSES = ['open', 'closed'] as const

export type CountStatus = (typeof COUNT_STATUSES)[number]

export const COUNT_STATUS_LABELS: Record<CountStatus, string> = {
  open: 'Abierto',
  closed: 'Cerrado',
}

// ---- Ayudas ----

type Raw = string | number | null | undefined

// Límite técnico de `integer` en PostgreSQL, no una regla de negocio.
const MAX_INT = 2_147_483_647

function rawText(value: Raw): string {
  return value === null || value === undefined ? '' : String(value).trim()
}

function parseInteger(value: Raw, min: number): number | null {
  const text = rawText(value)
  if (!/^-?\d+$/.test(text)) return null
  const number = Number(text)
  return number >= min && number <= MAX_INT ? number : null
}

const optionalId = z.number().int().positive().nullable().optional()

function optionalText(max: number, message: string) {
  return z
    .string()
    .trim()
    .max(max, message)
    .transform((value) => (value === '' ? null : value))
}

// ---- Movimiento manual (US1, D-002, D-003) ----

const QUANTITY_MESSAGE = 'La cantidad debe ser un número entero mayor que 0.'
const REASON_REQUIRED = 'Indica el motivo.'

// Entrada del formulario: el ajuste usa una sola ubicación (`location_id`) y
// la dirección decide si va como destino (Aumentar) u origen (Disminuir).
export const movementFormSchema = z
  .object({
    type: z.enum(MANUAL_MOVEMENT_TYPES, { error: 'Selecciona la clase de movimiento.' }),
    // RN-007: solo tipos por cantidad (lo filtra el formulario).
    asset_type_id: z.number({ error: 'Selecciona el tipo de artículo.' }).int().positive('Selecciona el tipo de artículo.'),
    quantity: z.union([z.string(), z.number(), z.null(), z.undefined()]),
    from_location_id: optionalId,
    to_location_id: optionalId,
    location_id: optionalId,
    direction: z.enum(ADJUSTMENT_DIRECTIONS).nullable().optional(),
    reason: optionalText(500, 'El motivo admite máximo 500 caracteres.'),
  })
  .superRefine((value, ctx) => {
    const issue = (path: string, message: string) => ctx.addIssue({ code: 'custom', path: [path], message })
    if (parseInteger(value.quantity, 1) === null) issue('quantity', QUANTITY_MESSAGE)

    switch (value.type) {
      case 'entry':
        if (!value.to_location_id) issue('to_location_id', 'Selecciona la ubicación de destino.')
        break
      case 'exit':
        if (!value.from_location_id) issue('from_location_id', 'Selecciona la ubicación de origen.')
        if (value.reason === null) issue('reason', REASON_REQUIRED)
        break
      case 'transfer':
        if (!value.from_location_id) issue('from_location_id', 'Selecciona la ubicación de origen.')
        if (!value.to_location_id) issue('to_location_id', 'Selecciona la ubicación de destino.')
        else if (value.from_location_id === value.to_location_id) issue('to_location_id', 'El destino debe ser distinto del origen.')
        break
      case 'adjustment':
        if (!value.direction) issue('direction', 'Indica si el ajuste aumenta o disminuye la existencia.')
        if (!value.location_id) issue('location_id', 'Selecciona la ubicación.')
        if (value.reason === null) issue('reason', REASON_REQUIRED)
        break
    }
  })
  .transform((value) => {
    const payload = {
      type: value.type,
      asset_type_id: value.asset_type_id,
      quantity: parseInteger(value.quantity, 1) as number,
      reason: value.reason,
    }
    switch (value.type) {
      case 'entry':
        return { ...payload, to_location_id: value.to_location_id as number }
      case 'exit':
        return { ...payload, from_location_id: value.from_location_id as number }
      case 'transfer':
        return { ...payload, from_location_id: value.from_location_id as number, to_location_id: value.to_location_id as number }
      case 'adjustment':
        return value.direction === 'increase'
          ? { ...payload, to_location_id: value.location_id as number }
          : { ...payload, from_location_id: value.location_id as number }
    }
  })

export type MovementFormInput = z.input<typeof movementFormSchema>

// Cuerpo de POST /api/inventory/movements.
export type MovementValues = {
  type: ManualMovementType
  asset_type_id: number
  quantity: number
  from_location_id?: number
  to_location_id?: number
  reason: string | null
}

// ---- Conteos (US3, RN-027, D-004) ----

export const newCountSchema = z.object({
  location_id: z.number({ error: 'Selecciona la ubicación a contar.' }).int().positive('Selecciona la ubicación a contar.'),
  notes: optionalText(500, 'Las notas admiten máximo 500 caracteres.'),
})

export type NewCountValues = z.output<typeof newCountSchema>

const COUNTED_MESSAGE = 'La cantidad contada debe ser un número entero mayor o igual a 0.'

// Vacío = todavía sin contar (null); el cierre exige todos contados (D-004).
const countedQuantity = z.union([z.string(), z.number(), z.null()]).transform((value, ctx): number | null => {
  if (rawText(value) === '') return null
  const number = parseInteger(value, 0)
  if (number === null) {
    ctx.addIssue({ code: 'custom', message: COUNTED_MESSAGE })
    return z.NEVER
  }
  return number
})

// Renglón de cantidad (existente por `id`, o nuevo de un tipo sin existencia
// por `asset_type_id`) con `counted_quantity`, o de un activo serializado
// (`asset_id`) con la casilla `found`. Forma del PATCH .../lines del plan.
const positiveId = z.number().int().positive().optional()

export const countLinesSchema = z.array(
  z.object({
    id: positiveId,
    asset_type_id: positiveId,
    asset_id: positiveId,
    counted_quantity: countedQuantity.optional(),
    found: z.boolean().optional(),
  })
)

export type CountLineValues = z.output<typeof countLinesSchema>[number]
