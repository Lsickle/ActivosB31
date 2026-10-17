import { describe, expect, it } from 'vitest'
import { deliveryFormSchema, returnFormSchema, MAX_SIGNATURE_BYTES, type ReturnFormRow } from './schemas'

const TODAY = '2026-10-17'
const SIGNATURE = 'data:image/png;base64,iVBORw0KGgo='

function delivery(overrides: Record<string, unknown> = {}) {
  return {
    employee_id: 10,
    kind: 'dotacion',
    due_on: '',
    from_location_id: 4,
    notes: '',
    signature: SIGNATURE,
    items: [{ asset_id: 7 }, { asset_type_id: 5, quantity: '2' }],
    ...overrides,
  }
}

function messages(result: { success: boolean; error?: { issues: { path: PropertyKey[]; message: string }[] } }) {
  return Object.fromEntries((result.error?.issues ?? []).map((issue) => [issue.path.join('.'), issue.message]))
}

describe('deliveryFormSchema (007 FR-001, RN-023..RN-025)', () => {
  const schema = deliveryFormSchema(TODAY)

  it('acepta una dotación con un activo y una cantidad; normaliza el payload', () => {
    const result = schema.safeParse(delivery())
    expect(result.success).toBe(true)
    expect(result.data).toEqual({
      employee_id: 10,
      kind: 'dotacion',
      due_on: null,
      from_location_id: 4,
      notes: null,
      signature: SIGNATURE,
      items: [{ asset_id: 7 }, { asset_type_id: 5, quantity: 2 }],
    })
  })

  it('dotación: descarta una fecha esperada (es asignación permanente, RN-023)', () => {
    const result = schema.safeParse(delivery({ due_on: '2026-12-01' }))
    expect(result.data?.due_on).toBeNull()
  })

  it('préstamo: exige fecha esperada (US1-2) y que no sea anterior a hoy', () => {
    expect(messages(schema.safeParse(delivery({ kind: 'prestamo' })))).toMatchObject({
      due_on: 'Indica la fecha de devolución esperada.',
    })
    expect(messages(schema.safeParse(delivery({ kind: 'prestamo', due_on: '2026-10-16' })))).toMatchObject({
      due_on: 'La fecha de devolución no puede ser anterior a hoy.',
    })
    const ok = schema.safeParse(delivery({ kind: 'prestamo', due_on: TODAY }))
    expect(ok.success).toBe(true)
    expect(ok.data?.due_on).toBe(TODAY)
  })

  it('exige empleado, tipo y ubicación de origen', () => {
    const result = messages(schema.safeParse(delivery({ employee_id: null, kind: null, from_location_id: null })))
    expect(result).toMatchObject({
      employee_id: 'Selecciona el empleado.',
      kind: 'Selecciona el tipo de entrega.',
      from_location_id: 'Selecciona la ubicación de origen.',
    })
  })

  it('exige al menos un artículo (RN-025)', () => {
    expect(messages(schema.safeParse(delivery({ items: [] })))).toMatchObject({ items: 'Agrega al menos un artículo.' })
  })

  it('cantidades enteras mayores que 0', () => {
    expect(messages(schema.safeParse(delivery({ items: [{ asset_type_id: 5, quantity: '0' }] })))).toMatchObject({
      'items.0.quantity': 'La cantidad debe ser un número entero mayor que 0.',
    })
    expect(schema.safeParse(delivery({ items: [{ asset_type_id: 5, quantity: '1.5' }] })).success).toBe(false)
  })

  it('no repite un activo ni un tipo por cantidad', () => {
    expect(messages(schema.safeParse(delivery({ items: [{ asset_id: 7 }, { asset_id: 7 }] })))).toMatchObject({
      items: 'Hay artículos repetidos.',
    })
  })

  it('exige la firma PNG (RN-024, US1-3) y limita su tamaño (D-002)', () => {
    expect(messages(schema.safeParse(delivery({ signature: null })))).toMatchObject({
      signature: 'La firma del empleado es obligatoria.',
    })
    expect(messages(schema.safeParse(delivery({ signature: 'data:image/jpeg;base64,AAAA' })))).toMatchObject({
      signature: 'La firma del empleado es obligatoria.',
    })
    const huge = `data:image/png;base64,${'A'.repeat(Math.ceil((MAX_SIGNATURE_BYTES * 4) / 3) + 8)}`
    expect(messages(schema.safeParse(delivery({ signature: huge })))).toMatchObject({
      signature: 'La firma es demasiado grande. Límpiala y firma de nuevo.',
    })
  })

  it('observaciones opcionales con límite', () => {
    expect(schema.safeParse(delivery({ notes: '  Entrega de turno  ' })).data?.notes).toBe('Entrega de turno')
    expect(schema.safeParse(delivery({ notes: 'x'.repeat(1001) })).success).toBe(false)
  })
})

function row(overrides: Partial<ReturnFormRow> = {}): ReturnFormRow {
  return {
    delivery_item_id: 1,
    serialized: false,
    pending_quantity: 2,
    quantity: '1',
    condition: 'good',
    to_location_id: 4,
    notes: '',
    ...overrides,
  }
}

describe('returnFormSchema (007 FR-003, RN-026, RN-038, D-004)', () => {
  it('bueno: exige ubicación de destino y envía solo lo necesario', () => {
    const result = returnFormSchema.safeParse({ items: [row()] })
    expect(result.data).toEqual({ items: [{ delivery_item_id: 1, quantity: 1, condition: 'good', to_location_id: 4 }] })

    expect(messages(returnFormSchema.safeParse({ items: [row({ to_location_id: null })] }))).toMatchObject({
      'items.0.to_location_id': 'Selecciona la ubicación a la que vuelve.',
    })
  })

  it('serializado dañado: exige ubicación (queda En mantenimiento allí)', () => {
    expect(
      messages(returnFormSchema.safeParse({ items: [row({ serialized: true, pending_quantity: 1, condition: 'damaged', to_location_id: null })] }))
    ).toMatchObject({ 'items.0.to_location_id': 'Selecciona la ubicación a la que vuelve.' })
  })

  it('por cantidad dañado: no vuelve al stock (RN-038), no lleva ubicación', () => {
    const result = returnFormSchema.safeParse({ items: [row({ condition: 'damaged', to_location_id: 4 })] })
    expect(result.data?.items[0]).toEqual({ delivery_item_id: 1, quantity: 1, condition: 'damaged' })
  })

  it('perdido: exige observación y no lleva ubicación (RN-026)', () => {
    expect(messages(returnFormSchema.safeParse({ items: [row({ condition: 'lost' })] }))).toMatchObject({
      'items.0.notes': 'Describe qué pasó con el artículo perdido.',
    })
    const ok = returnFormSchema.safeParse({ items: [row({ condition: 'lost', notes: ' Se cayó en el incendio ' })] })
    expect(ok.data?.items[0]).toEqual({ delivery_item_id: 1, quantity: 1, condition: 'lost', notes: 'Se cayó en el incendio' })
  })

  it('la cantidad no supera lo pendiente', () => {
    expect(messages(returnFormSchema.safeParse({ items: [row({ quantity: '3' })] }))).toMatchObject({
      'items.0.quantity': 'Máximo 2 (pendiente).',
    })
  })

  it('omite los renglones sin cantidad y exige al menos uno', () => {
    const result = returnFormSchema.safeParse({ items: [row({ quantity: '' }), row({ delivery_item_id: 2, quantity: '2' })] })
    expect(result.data?.items).toEqual([{ delivery_item_id: 2, quantity: 2, condition: 'good', to_location_id: 4 }])

    expect(messages(returnFormSchema.safeParse({ items: [row({ quantity: '' })] }))).toMatchObject({
      items: 'Indica al menos un artículo a devolver.',
    })
  })

  it('exige el estado de lo que se devuelve', () => {
    expect(messages(returnFormSchema.safeParse({ items: [row({ condition: null })] }))).toMatchObject({
      'items.0.condition': 'Indica el estado.',
    })
  })
})
