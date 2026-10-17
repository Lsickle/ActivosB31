import { describe, expect, it } from 'vitest'
import { TRACKING_MODE_LABELS, assetTypeFormSchema, catalogFormSchema, locationFormSchema } from './schemas'

describe('Esquemas de configuración (spec 002, edge cases)', () => {
  it('recorta el nombre y convierte la descripción vacía en null', () => {
    expect(catalogFormSchema.parse({ name: '  EPP  ', description: '   ' })).toEqual({ name: 'EPP', description: null })
    expect(catalogFormSchema.parse({ name: 'EPP', description: ' Protección ' })).toEqual({ name: 'EPP', description: 'Protección' })
  })

  it('rechaza nombre vacío o solo espacios', () => {
    const result = catalogFormSchema.safeParse({ name: '   ', description: '' })
    expect(result.success).toBe(false)
    expect(result.error?.issues[0]?.message).toBe('Ingresa el nombre.')
  })

  it('limita el nombre a 120 y la descripción a 500 caracteres', () => {
    expect(catalogFormSchema.safeParse({ name: 'a'.repeat(120), description: 'b'.repeat(500) }).success).toBe(true)

    const longName = catalogFormSchema.safeParse({ name: 'a'.repeat(121), description: '' })
    expect(longName.error?.issues[0]?.message).toBe('El nombre admite máximo 120 caracteres.')

    const longDescription = catalogFormSchema.safeParse({ name: 'EPP', description: 'b'.repeat(501) })
    expect(longDescription.error?.issues[0]?.message).toBe('La descripción admite máximo 500 caracteres.')
  })

  it('tipo de activo: exige categoría y modo de control (RN-007, US2 escenario 6)', () => {
    const missing = assetTypeFormSchema.safeParse({ name: 'Casco', description: '' })
    expect(missing.success).toBe(false)
    const messages = Object.fromEntries(missing.error!.issues.map((issue) => [issue.path[0], issue.message]))
    expect(messages).toEqual({
      asset_category_id: 'Selecciona una categoría.',
      tracking_mode: 'Selecciona el modo de control.',
    })

    expect(
      assetTypeFormSchema.parse({ name: 'Casco', description: '', asset_category_id: 2, tracking_mode: 'serialized' })
    ).toEqual({ name: 'Casco', description: null, asset_category_id: 2, tracking_mode: 'serialized', min_stock: null })
  })

  it('tipo de activo: stock mínimo opcional (entero ≥ 0) solo en tipos por cantidad (RN-028, 005 D-008)', () => {
    const base = { name: 'Guantes', description: '', asset_category_id: 1 }
    expect(assetTypeFormSchema.parse({ ...base, tracking_mode: 'quantity', min_stock: 10 }).min_stock).toBe(10)
    expect(assetTypeFormSchema.parse({ ...base, tracking_mode: 'quantity', min_stock: null }).min_stock).toBeNull()
    // Con serializado el backend lo rechaza: se envía null aunque el campo tuviera valor.
    expect(assetTypeFormSchema.parse({ ...base, tracking_mode: 'serialized', min_stock: 10 }).min_stock).toBeNull()

    const negative = assetTypeFormSchema.safeParse({ ...base, tracking_mode: 'quantity', min_stock: -1 })
    expect(negative.error?.issues[0]).toMatchObject({ path: ['min_stock'], message: 'El stock mínimo debe ser un número entero mayor o igual a 0.' })
    const decimal = assetTypeFormSchema.safeParse({ ...base, tracking_mode: 'quantity', min_stock: 1.5 })
    expect(decimal.error?.issues[0]?.message).toBe('El stock mínimo debe ser un número entero mayor o igual a 0.')
  })

  it('ubicación: exige tipo de ubicación (RN-002)', () => {
    const missing = locationFormSchema.safeParse({ name: 'Máquina 1', description: '' })
    expect(missing.error?.issues[0]?.message).toBe('Selecciona un tipo de ubicación.')
    expect(locationFormSchema.parse({ name: 'Máquina 1', description: '', location_type_id: 1 })).toEqual({
      name: 'Máquina 1',
      description: null,
      location_type_id: 1,
    })
  })

  it('etiquetas del modo de control', () => {
    expect(TRACKING_MODE_LABELS).toEqual({ serialized: 'Serializado', quantity: 'Por cantidad' })
  })
})
