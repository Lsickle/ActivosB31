import { describe, expect, it } from 'vitest'
import type { AssetEventRecord } from 'app/features/activos/api'
import { describeEvent } from './AssetHistory'

function event(overrides: Partial<AssetEventRecord>): AssetEventRecord {
  return {
    id: 1,
    event: 'created',
    from_status: null,
    to_status: null,
    from_location: null,
    to_location: null,
    employee: null,
    user: null,
    notes: null,
    changes: null,
    created_at: '2026-10-17T15:00:00-05:00',
    ...overrides,
  } as AssetEventRecord
}

describe('describeEvent: planes de mantenimiento (A-1 de 005)', () => {
  it('describe la creación de un plan con su nombre', () => {
    expect(describeEvent(event({ event: 'maintenance_plan_created', notes: 'Prueba hidrostática' }))).toEqual({
      title: 'Plan de mantenimiento creado',
      details: ['Prueba hidrostática'],
    })
  })

  it('describe la modificación de un plan con los campos cambiados', () => {
    const result = describeEvent(
      event({
        event: 'maintenance_plan_updated',
        notes: 'Prueba hidrostática',
        changes: { interval_months: { from: 60, to: 36 }, is_active: { from: true, to: false } },
      })
    )

    expect(result.title).toBe('Plan de mantenimiento modificado')
    expect(result.details).toEqual(['Prueba hidrostática', 'Periodicidad (meses): 60 → 36', 'Activo: true → false'])
  })
})
