import { describe, expect, it } from 'vitest'
import { REPORT_FORMAT_LABELS, dateRangeError, reportFileName } from './schemas'

// RN-033: rango de fechas inclusivo (Desde/Hasta), ambos opcionales.
describe('dateRangeError (RN-033)', () => {
  it('acepta rango vacío, solo uno de los extremos o Desde ≤ Hasta', () => {
    expect(dateRangeError('', '')).toBeNull()
    expect(dateRangeError('2026-10-01', '')).toBeNull()
    expect(dateRangeError('', '2026-10-17')).toBeNull()
    expect(dateRangeError('2026-10-17', '2026-10-17')).toBeNull()
    expect(dateRangeError('2026-10-01', '2026-10-17')).toBeNull()
  })

  it('rechaza fechas inválidas y Desde posterior a Hasta', () => {
    expect(dateRangeError('2026-13-01', '')).toBe('La fecha "Desde" no es válida.')
    expect(dateRangeError('', '17/10/2026')).toBe('La fecha "Hasta" no es válida.')
    expect(dateRangeError('2026-10-18', '2026-10-17')).toBe('"Desde" no puede ser posterior a "Hasta".')
  })
})

describe('formatos de exportación (RN-032)', () => {
  it('Excel (.xlsx) y PDF', () => {
    expect(REPORT_FORMAT_LABELS).toEqual({ xlsx: 'Excel', pdf: 'PDF' })
  })

  it('nombre de respaldo cuando el servidor no envía Content-Disposition', () => {
    expect(reportFileName('inventory', 'xlsx', '2026-10-17')).toBe('inventario-2026-10-17.xlsx')
    expect(reportFileName('employee', 'pdf', '2026-10-17')).toBe('reporte-empleado-2026-10-17.pdf')
    expect(reportFileName('asset', 'pdf', '2026-10-17')).toBe('reporte-activo-2026-10-17.pdf')
    expect(reportFileName('asset-type', 'xlsx', '2026-10-17')).toBe('reporte-tipo-2026-10-17.xlsx')
  })
})
