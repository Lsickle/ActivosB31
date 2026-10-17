import { z } from 'zod'

// Reglas de specs/002-modulo-configuracion (edge cases y data-model.md): nombre
// obligatorio, recortado, ≤120; descripción opcional ≤500 (vacía → null). El
// backend valida igual (y la unicidad sin mayúsculas, FR-006); esto adelanta
// los mensajes junto al campo (FR-012).

export const TRACKING_MODES = ['serialized', 'quantity'] as const

export type TrackingMode = (typeof TRACKING_MODES)[number]

// RN-007: el tipo de activo define si se controla uno a uno o por cantidad.
export const TRACKING_MODE_LABELS: Record<TrackingMode, string> = {
  serialized: 'Serializado',
  quantity: 'Por cantidad',
}

export const catalogFormSchema = z.object({
  name: z.string().trim().min(1, 'Ingresa el nombre.').max(120, 'El nombre admite máximo 120 caracteres.'),
  description: z
    .string()
    .trim()
    .max(500, 'La descripción admite máximo 500 caracteres.')
    .transform((value) => (value === '' ? null : value)),
})

export type CatalogFormValues = z.infer<typeof catalogFormSchema>

function requiredId(message: string) {
  return z.number({ error: message }).int().positive(message)
}

// Categorías de activo y tipos de ubicación (RN-004) solo tienen nombre y descripción.
export const assetCategoryFormSchema = catalogFormSchema
export const locationTypeFormSchema = catalogFormSchema

export type AssetCategoryFormValues = CatalogFormValues
export type LocationTypeFormValues = CatalogFormValues

const MIN_STOCK_MESSAGE = 'El stock mínimo debe ser un número entero mayor o igual a 0.'

// US2: cada tipo pertenece a una categoría y tiene modo de control obligatorio (RN-007).
// 005 D-008 / RN-028: stock mínimo opcional, solo en tipos por cantidad; con
// serializado se envía null (el backend lo rechaza si llega con valor).
export const assetTypeFormSchema = catalogFormSchema
  .extend({
    asset_category_id: requiredId('Selecciona una categoría.'),
    tracking_mode: z.enum(TRACKING_MODES, { error: 'Selecciona el modo de control.' }),
    min_stock: z
      .number({ error: MIN_STOCK_MESSAGE })
      .int(MIN_STOCK_MESSAGE)
      .min(0, MIN_STOCK_MESSAGE)
      .max(2_147_483_647, MIN_STOCK_MESSAGE)
      .nullable()
      .optional(),
  })
  .transform(({ min_stock, ...values }) => ({
    ...values,
    min_stock: values.tracking_mode === 'quantity' ? (min_stock ?? null) : null,
  }))

export type AssetTypeFormValues = z.output<typeof assetTypeFormSchema>

// RN-002: ubicación plana, con exactamente un tipo de ubicación.
export const locationFormSchema = catalogFormSchema.extend({
  location_type_id: requiredId('Selecciona un tipo de ubicación.'),
})

export type LocationFormValues = z.infer<typeof locationFormSchema>
