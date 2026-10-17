'use client'

import { useCallback, useEffect, useId, useImperativeHandle, useRef, useState } from 'react'
import SignaturePadLib from 'signature_pad'
import { EraserIcon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

// RN-024 / 007 plan "Frontend": firma en pantalla del empleado que recibe.
// Usa signature_pad 5.1.4 (docs/infraestructura.md). El canvas se ajusta al
// devicePixelRatio para que el trazo no se vea borroso y, al redimensionar,
// se recargan los trazos con toData/fromData (cambiar el tamaño del canvas lo
// borra). El resultado se exporta como PNG (D-002).

export type SignaturePadHandle = {
  isEmpty: () => boolean
  /** Data URL `image/png`, o null si no hay firma. */
  toDataURL: () => string | null
  clear: () => void
}

export function SignaturePad({
  ref,
  label,
  description = 'Firme dentro del recuadro con el dedo, un lápiz táctil o el mouse.',
  error,
  onChange,
  className,
}: {
  ref?: React.Ref<SignaturePadHandle>
  /** Nombre accesible del área de firma. */
  label: string
  description?: string
  error?: string
  /** Se llama al terminar cada trazo (PNG) y al limpiar (null). */
  onChange?: (dataUrl: string | null) => void
  className?: string
}) {
  const id = useId()
  const descriptionId = `${id}-description`
  const errorId = `${id}-error`
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const padRef = useRef<SignaturePadLib | null>(null)
  const sizeRef = useRef<{ width: number; height: number; ratio: number } | null>(null)
  const onChangeRef = useRef(onChange)
  const [isEmpty, setIsEmpty] = useState(true)

  useEffect(() => {
    onChangeRef.current = onChange
  }, [onChange])

  const resize = useCallback(() => {
    const canvas = canvasRef.current
    const pad = padRef.current
    if (!canvas || !pad) return
    const ratio = Math.max(window.devicePixelRatio || 1, 1)
    const width = canvas.offsetWidth
    const height = canvas.offsetHeight
    const previous = sizeRef.current
    // En móviles el scroll dispara "resize" sin cambiar el ancho: no se toca.
    if (previous && previous.width === width && previous.height === height && previous.ratio === ratio) return
    sizeRef.current = { width, height, ratio }

    const strokes = pad.toData()
    canvas.width = width * ratio
    canvas.height = height * ratio
    canvas.getContext('2d')?.scale(ratio, ratio)
    pad.clear()
    if (strokes.length > 0) pad.fromData(strokes)
  }, [])

  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    const pad = new SignaturePadLib(canvas, { penColor: '#0f172a', backgroundColor: 'rgba(0,0,0,0)' })
    padRef.current = pad

    const handleEndStroke = () => {
      setIsEmpty(pad.isEmpty())
      onChangeRef.current?.(pad.isEmpty() ? null : pad.toDataURL('image/png'))
    }
    pad.addEventListener('endStroke', handleEndStroke)

    resize()
    window.addEventListener('resize', resize)
    const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(() => resize())
    observer?.observe(canvas)

    return () => {
      window.removeEventListener('resize', resize)
      observer?.disconnect()
      pad.removeEventListener('endStroke', handleEndStroke)
      pad.off()
      padRef.current = null
      sizeRef.current = null
    }
  }, [resize])

  const clear = useCallback(() => {
    padRef.current?.clear()
    setIsEmpty(true)
    onChangeRef.current?.(null)
  }, [])

  useImperativeHandle(
    ref,
    () => ({
      isEmpty: () => padRef.current?.isEmpty() ?? true,
      toDataURL: () => {
        const pad = padRef.current
        return pad && !pad.isEmpty() ? pad.toDataURL('image/png') : null
      },
      clear,
    }),
    [clear]
  )

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <div
        className={cn(
          'relative overflow-hidden rounded-lg border border-dashed bg-white',
          error ? 'border-destructive' : 'border-input'
        )}
      >
        <canvas
          ref={canvasRef}
          role="img"
          aria-label={label}
          aria-describedby={error ? `${descriptionId} ${errorId}` : descriptionId}
          className="block h-48 w-full touch-none sm:h-56"
        />
        {isEmpty && (
          <span aria-hidden className="pointer-events-none absolute inset-x-6 bottom-8 border-b border-slate-300 pb-1 text-xs text-slate-400">
            Firma
          </span>
        )}
      </div>
      <div className="flex items-start justify-between gap-3">
        <p id={descriptionId} className="text-xs text-muted-foreground">
          {description}
        </p>
        <Button type="button" variant="outline" size="sm" onClick={clear} disabled={isEmpty} aria-label="Limpiar firma">
          <EraserIcon data-icon="inline-start" />
          Limpiar
        </Button>
      </div>
      {error && (
        <p id={errorId} className="text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}
