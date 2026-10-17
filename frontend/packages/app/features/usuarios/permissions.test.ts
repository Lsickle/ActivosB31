import { describe, expect, it } from 'vitest'
import { hasSamePermissions, togglePermission } from './permissions'

// RN-009 / FR-002: "gestionar" implica "ver" en el mismo módulo.
describe('togglePermission', () => {
  it('marcar gestionar marca también ver', () => {
    expect(togglePermission([], 'empleados', 'gestionar', true)).toEqual(['empleados.gestionar', 'empleados.ver'])
  })

  it('quitar ver quita también gestionar', () => {
    expect(togglePermission(['activos.ver', 'empleados.gestionar', 'empleados.ver'], 'empleados', 'ver', false)).toEqual([
      'activos.ver',
    ])
  })

  it('quitar gestionar conserva ver', () => {
    expect(togglePermission(['empleados.gestionar', 'empleados.ver'], 'empleados', 'gestionar', false)).toEqual(['empleados.ver'])
  })

  it('marcar ver no duplica ni agrega gestionar', () => {
    expect(togglePermission(['empleados.ver'], 'empleados', 'ver', true)).toEqual(['empleados.ver'])
  })
})

describe('hasSamePermissions', () => {
  it('compara como conjuntos', () => {
    expect(hasSamePermissions(['a.ver', 'b.ver'], ['b.ver', 'a.ver'])).toBe(true)
    expect(hasSamePermissions(['a.ver'], ['a.ver', 'a.gestionar'])).toBe(false)
  })
})
