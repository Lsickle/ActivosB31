import { act, createRef } from 'react'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SignaturePad, type SignaturePadHandle } from './signature-pad'

// RN-024 / 007 plan "Frontend": firma en pantalla con signature_pad 5.1.4.
// jsdom no dibuja en canvas: se reemplaza la librería por un doble que
// registra las llamadas y permite simular un trazo.

type Listener = () => void

// vi.mock se eleva sobre los imports: el doble se define con vi.hoisted.
const { FakePad, instances } = vi.hoisted(() => {
  const instances: InstanceType<typeof FakePad>[] = []

  class FakePad {
    canvas: HTMLCanvasElement
    options: unknown
    empty = true
    data: unknown[] = []
    listeners = new Map<string, Listener[]>()
    clear = vi.fn(() => {
      this.empty = true
      this.data = []
    })
    off = vi.fn()
    toData = vi.fn(() => this.data)
    fromData = vi.fn((data: unknown[]) => {
      this.data = data
      this.empty = data.length === 0
    })
    toDataURL = vi.fn(() => 'data:image/png;base64,AAAA')
    isEmpty = () => this.empty

    constructor(canvas: HTMLCanvasElement, options?: unknown) {
      this.canvas = canvas
      this.options = options
      instances.push(this)
    }

    addEventListener(type: string, listener: Listener) {
      this.listeners.set(type, [...(this.listeners.get(type) ?? []), listener])
    }

    removeEventListener(type: string, listener: Listener) {
      this.listeners.set(
        type,
        (this.listeners.get(type) ?? []).filter((item) => item !== listener)
      )
    }

    // Simula que el usuario terminó un trazo.
    stroke() {
      this.empty = false
      this.data = [...this.data, { points: [{ x: 1, y: 1 }] }]
      this.listeners.get('endStroke')?.forEach((listener) => listener())
    }
  }

  return { FakePad, instances }
})

type FakePad = InstanceType<typeof FakePad>

vi.mock('signature_pad', () => ({ default: FakePad }))

const scale = vi.fn()
const drawImage = vi.fn()

function lastPad(): FakePad {
  const pad = instances.at(-1)
  if (!pad) throw new Error('No se creó el SignaturePad')
  return pad
}

describe('SignaturePad (RN-024, 007 T007)', () => {
  let width = 400

  beforeEach(() => {
    instances.length = 0
    scale.mockReset()
    drawImage.mockReset()
    width = 400
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ scale, drawImage } as unknown as CanvasRenderingContext2D)
    vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockImplementation(() => width)
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(() => 200)
    Object.defineProperty(window, 'devicePixelRatio', { configurable: true, value: 2 })
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('es accesible: canvas con nombre y texto de ayuda asociado', () => {
    render(<SignaturePad label="Firma del empleado" />)

    const canvas = screen.getByRole('img', { name: 'Firma del empleado' })
    expect(canvas.tagName).toBe('CANVAS')
    expect(canvas).toHaveAccessibleDescription(/Firme dentro del recuadro/)
    expect(screen.getByRole('button', { name: 'Limpiar firma' })).toBeDisabled()
  })

  it('ajusta el canvas al devicePixelRatio', () => {
    render(<SignaturePad label="Firma" />)

    const canvas = screen.getByRole('img', { name: 'Firma' }) as HTMLCanvasElement
    expect(canvas.width).toBe(800)
    expect(canvas.height).toBe(400)
    expect(scale).toHaveBeenCalledWith(2, 2)
    expect(lastPad().canvas).toBe(canvas)
  })

  it('al terminar un trazo informa el PNG y deja de estar vacío', () => {
    const onChange = vi.fn()
    const ref = createRef<SignaturePadHandle>()
    render(<SignaturePad ref={ref} label="Firma" onChange={onChange} />)

    expect(ref.current?.isEmpty()).toBe(true)
    expect(ref.current?.toDataURL()).toBeNull()

    act(() => lastPad().stroke())

    expect(onChange).toHaveBeenLastCalledWith('data:image/png;base64,AAAA')
    expect(lastPad().toDataURL).toHaveBeenCalledWith('image/png')
    expect(ref.current?.isEmpty()).toBe(false)
    expect(ref.current?.toDataURL()).toBe('data:image/png;base64,AAAA')
    expect(screen.getByRole('button', { name: 'Limpiar firma' })).toBeEnabled()
  })

  it('B-4: si el canvas supera 1000 px de ancho exporta una copia escalada (misma proporción)', () => {
    width = 600 // 1200 × 400 px reales con devicePixelRatio 2
    const scaledToDataURL = vi
      .spyOn(HTMLCanvasElement.prototype, 'toDataURL')
      .mockReturnValue('data:image/png;base64,ESCALADA')
    const onChange = vi.fn()
    const ref = createRef<SignaturePadHandle>()
    render(<SignaturePad ref={ref} label="Firma" onChange={onChange} />)
    const canvas = screen.getByRole('img', { name: 'Firma' }) as HTMLCanvasElement

    act(() => lastPad().stroke())

    expect(onChange).toHaveBeenLastCalledWith('data:image/png;base64,ESCALADA')
    expect(ref.current?.toDataURL()).toBe('data:image/png;base64,ESCALADA')
    expect(lastPad().toDataURL).not.toHaveBeenCalled()
    expect(drawImage).toHaveBeenCalledWith(canvas, 0, 0, 1000, 333)
    const exported = scaledToDataURL.mock.contexts.at(-1) as HTMLCanvasElement
    expect(exported).not.toBe(canvas)
    expect(exported.width).toBe(1000)
    expect(exported.height).toBe(333)
    expect(scaledToDataURL).toHaveBeenLastCalledWith('image/png')
  })

  it('"Limpiar" borra la firma e informa null', async () => {
    const onChange = vi.fn()
    render(<SignaturePad label="Firma" onChange={onChange} />)
    act(() => lastPad().stroke())

    await userEvent.click(screen.getByRole('button', { name: 'Limpiar firma' }))

    expect(lastPad().clear).toHaveBeenCalled()
    expect(onChange).toHaveBeenLastCalledWith(null)
    expect(screen.getByRole('button', { name: 'Limpiar firma' })).toBeDisabled()
  })

  it('conserva los trazos al redimensionar (toData / fromData)', () => {
    render(<SignaturePad label="Firma" />)
    const pad = lastPad()
    act(() => pad.stroke())
    const strokes = pad.data

    width = 300
    act(() => {
      window.dispatchEvent(new Event('resize'))
    })

    const canvas = screen.getByRole('img', { name: 'Firma' }) as HTMLCanvasElement
    expect(canvas.width).toBe(600)
    expect(pad.toData).toHaveBeenCalled()
    expect(pad.fromData).toHaveBeenLastCalledWith(strokes)
    expect(pad.isEmpty()).toBe(false)
  })

  it('no redibuja si el tamaño no cambió (p. ej. scroll en móvil)', () => {
    render(<SignaturePad label="Firma" />)
    const pad = lastPad()
    act(() => {
      window.dispatchEvent(new Event('resize'))
    })
    expect(pad.fromData).not.toHaveBeenCalled()
  })

  it('libera los eventos de la librería al desmontar', () => {
    const { unmount } = render(<SignaturePad label="Firma" />)
    const pad = lastPad()
    unmount()
    expect(pad.off).toHaveBeenCalled()
  })

  it('muestra el error asociado al canvas', () => {
    render(<SignaturePad label="Firma" error="La firma es obligatoria." />)
    const canvas = screen.getByRole('img', { name: 'Firma' })
    expect(canvas).toHaveAccessibleDescription(/La firma es obligatoria\./)
  })
})
