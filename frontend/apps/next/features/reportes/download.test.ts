import { afterEach, describe, expect, it, vi } from 'vitest'
import { saveFile } from './download'

// 008 D-002: el archivo descargado con fetch se entrega al navegador con un <a download>.
describe('saveFile', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  it('crea un enlace temporal con el nombre del servidor, lo pulsa y libera la URL', () => {
    vi.useFakeTimers()
    const createObjectURL = vi.fn().mockReturnValue('blob:reporte')
    const revokeObjectURL = vi.fn()
    vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      expect(this.download).toBe('inventario-2026-10-17.xlsx')
      expect(this.getAttribute('href')).toBe('blob:reporte')
      expect(document.body.contains(this)).toBe(true)
    })
    const blob = new Blob(['x'])

    saveFile({ blob, filename: 'inventario-2026-10-17.xlsx' }, 'respaldo.xlsx')

    expect(createObjectURL).toHaveBeenCalledWith(blob)
    expect(click).toHaveBeenCalledTimes(1)
    expect(document.querySelector('a[download]')).toBeNull()
    vi.runAllTimers()
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:reporte')
  })

  it('usa el nombre de respaldo si el servidor no envió uno', () => {
    vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn().mockReturnValue('blob:x'), revokeObjectURL: vi.fn() })
    const names: string[] = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
      names.push(this.download)
    })

    saveFile({ blob: new Blob(['x']), filename: null }, 'reporte-empleado-2026-10-17.pdf')

    expect(names).toEqual(['reporte-empleado-2026-10-17.pdf'])
  })
})
