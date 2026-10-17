import { describe, expect, it } from 'vitest'
import {
  MANUAL_MOVEMENT_TYPES,
  MOVEMENT_TYPE_LABELS,
  countLinesSchema,
  movementFormSchema,
  newCountSchema,
} from './schemas'

function issues(result: { success: boolean; error?: { issues: { path: PropertyKey[]; message: string }[] } }) {
  return Object.fromEntries((result.error?.issues ?? []).map((issue) => [String(issue.path[0]), issue.message]))
}

const base = {
  asset_type_id: 5,
  quantity: '20',
  from_location_id: null,
  to_location_id: null,
  location_id: null,
  direction: null,
  reason: '',
}

describe('esquemas de inventario (006)', () => {
  it('etiqueta los siete tipos de movimiento (D-002) y solo ofrece a mano entrada, salida, traslado y ajuste', () => {
    expect(MOVEMENT_TYPE_LABELS).toEqual({
      entry: 'Entrada',
      exit: 'Salida',
      transfer: 'Traslado',
      adjustment: 'Ajuste',
      delivery: 'Entrega',
      return: 'Devolución',
      loss: 'Pérdida',
    })
    expect(MANUAL_MOVEMENT_TYPES).toEqual(['entry', 'exit', 'transfer', 'adjustment'])
  })

  it('entrada: destino obligatorio, motivo opcional; solo envía to_location_id', () => {
    expect(movementFormSchema.parse({ ...base, type: 'entry', to_location_id: 4 })).toEqual({
      type: 'entry',
      asset_type_id: 5,
      quantity: 20,
      to_location_id: 4,
      reason: null,
    })
    expect(issues(movementFormSchema.safeParse({ ...base, type: 'entry' }))).toEqual({
      to_location_id: 'Selecciona la ubicación de destino.',
    })
  })

  it('cantidad entera mayor que 0', () => {
    for (const quantity of ['', '0', '-3', '2.5', 'abc']) {
      expect(issues(movementFormSchema.safeParse({ ...base, type: 'entry', to_location_id: 4, quantity }))).toEqual({
        quantity: 'La cantidad debe ser un número entero mayor que 0.',
      })
    }
  })

  it('exige el tipo de artículo y la clase de movimiento', () => {
    expect(issues(movementFormSchema.safeParse({ ...base, type: undefined, asset_type_id: undefined }))).toMatchObject({
      type: 'Selecciona la clase de movimiento.',
      asset_type_id: 'Selecciona el tipo de artículo.',
    })
  })

  it('salida: origen y motivo obligatorios (FR-001)', () => {
    expect(issues(movementFormSchema.safeParse({ ...base, type: 'exit' }))).toEqual({
      from_location_id: 'Selecciona la ubicación de origen.',
      reason: 'Indica el motivo.',
    })
    expect(movementFormSchema.parse({ ...base, type: 'exit', from_location_id: 4, to_location_id: 8, reason: ' Daño ' })).toEqual({
      type: 'exit',
      asset_type_id: 5,
      quantity: 20,
      from_location_id: 4,
      reason: 'Daño',
    })
  })

  it('traslado: origen y destino obligatorios y distintos', () => {
    expect(issues(movementFormSchema.safeParse({ ...base, type: 'transfer' }))).toEqual({
      from_location_id: 'Selecciona la ubicación de origen.',
      to_location_id: 'Selecciona la ubicación de destino.',
    })
    expect(issues(movementFormSchema.safeParse({ ...base, type: 'transfer', from_location_id: 4, to_location_id: 4 }))).toEqual({
      to_location_id: 'El destino debe ser distinto del origen.',
    })
    expect(movementFormSchema.parse({ ...base, type: 'transfer', from_location_id: 4, to_location_id: 8 })).toEqual({
      type: 'transfer',
      asset_type_id: 5,
      quantity: 20,
      from_location_id: 4,
      to_location_id: 8,
      reason: null,
    })
  })

  it('ajuste: dirección, ubicación y motivo obligatorios; Aumentar → destino, Disminuir → origen', () => {
    expect(issues(movementFormSchema.safeParse({ ...base, type: 'adjustment' }))).toEqual({
      direction: 'Indica si el ajuste aumenta o disminuye la existencia.',
      location_id: 'Selecciona la ubicación.',
      reason: 'Indica el motivo.',
    })
    expect(
      movementFormSchema.parse({ ...base, type: 'adjustment', direction: 'increase', location_id: 4, reason: 'Conteo' })
    ).toEqual({ type: 'adjustment', asset_type_id: 5, quantity: 20, to_location_id: 4, reason: 'Conteo' })
    expect(
      movementFormSchema.parse({ ...base, type: 'adjustment', direction: 'decrease', location_id: 4, reason: 'Conteo' })
    ).toEqual({ type: 'adjustment', asset_type_id: 5, quantity: 20, from_location_id: 4, reason: 'Conteo' })
  })

  it('motivo de máximo 500 caracteres', () => {
    expect(
      issues(movementFormSchema.safeParse({ ...base, type: 'entry', to_location_id: 4, reason: 'x'.repeat(501) }))
    ).toEqual({ reason: 'El motivo admite máximo 500 caracteres.' })
  })

  it('nuevo conteo: ubicación obligatoria, notas opcionales', () => {
    expect(issues(newCountSchema.safeParse({ location_id: undefined, notes: '' }))).toEqual({
      location_id: 'Selecciona la ubicación a contar.',
    })
    expect(newCountSchema.parse({ location_id: 4, notes: '  ' })).toEqual({ location_id: 4, notes: null })
  })

  it('renglones del conteo: cantidad contada entera ≥ 0 (vacío = sin contar); serializados con "encontrado"', () => {
    expect(
      countLinesSchema.parse([
        { id: 1, counted_quantity: '13' },
        { id: 2, counted_quantity: '' },
        { id: 3, counted_quantity: '0' },
        { id: 4, asset_id: 9, found: true },
        { asset_type_id: 6, counted_quantity: '2' },
      ])
    ).toEqual([
      { id: 1, counted_quantity: 13 },
      { id: 2, counted_quantity: null },
      { id: 3, counted_quantity: 0 },
      { id: 4, asset_id: 9, found: true },
      { asset_type_id: 6, counted_quantity: 2 },
    ])

    const invalid = countLinesSchema.safeParse([{ id: 1, counted_quantity: '-1' }, { id: 2, counted_quantity: '1.5' }])
    expect(invalid.error?.issues.map((issue) => issue.path)).toEqual([
      [0, 'counted_quantity'],
      [1, 'counted_quantity'],
    ])
    expect(invalid.error?.issues[0]?.message).toBe('La cantidad contada debe ser un número entero mayor o igual a 0.')
  })
})
