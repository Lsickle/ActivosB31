import { describe, expect, it } from 'vitest'
import { dueState, formatDate, formatMoney } from './format'

describe('formato de Activos', () => {
  it('formatea fechas ISO sin desfase de zona horaria', () => {
    expect(formatDate('2027-03-10')).toBe('10 mar 2027')
    expect(formatDate('2026-12-01')).toBe('1 dic 2026')
    expect(formatDate(null)).toBe('—')
  })

  it('formatea pesos colombianos (acepta el decimal como texto de Laravel)', () => {
    expect(formatMoney('1250000.50')).toMatch(/1\.250\.000,5/)
    expect(formatMoney(0)).toMatch(/\$\s?0/)
    expect(formatMoney(null)).toBe('—')
  })

  it('RN-030: vencido antes de hoy, próximo dentro de la anticipación', () => {
    expect(dueState('2026-10-16', 30, '2026-10-17')).toBe('overdue')
    expect(dueState('2026-10-17', 30, '2026-10-17')).toBe('soon')
    expect(dueState('2026-11-16', 30, '2026-10-17')).toBe('soon')
    expect(dueState('2026-11-17', 30, '2026-10-17')).toBeNull()
    expect(dueState(null, 30, '2026-10-17')).toBeNull()
  })
})
