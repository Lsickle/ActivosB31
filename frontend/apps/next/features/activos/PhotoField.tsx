'use client'
/* eslint-disable @next/next/no-img-element -- vista previa local (blob:) o URL temporal firmada (FR-008): next/image no aplica. */

import { useEffect, useRef, useState } from 'react'
import { ImageIcon, XIcon } from 'lucide-react'
import { PHOTO_TYPES, photoError } from 'app/features/activos/schemas'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'

// Foto del activo (FR-008, edge case: JPEG/PNG/WebP ≤4 MB). Valida en el
// cliente y muestra una vista previa local; el servidor la re-codifica sin
// metadatos. Al editar muestra la actual (URL temporal) y permite quitarla.
export function PhotoField({
  id,
  file,
  onFileChange,
  currentUrl,
  removed,
  onRemovedChange,
  error,
}: {
  id: string
  file: File | null
  onFileChange: (file: File | null) => void
  currentUrl?: string | null
  removed?: boolean
  onRemovedChange?: (removed: boolean) => void
  /** Error del servidor (422 `photo`). */
  error?: string
}) {
  const inputRef = useRef<HTMLInputElement>(null)
  const [localError, setLocalError] = useState<string | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string | null>(null)

  // Libera la URL local de la vista previa al reemplazarla o al cerrar el formulario.
  useEffect(() => {
    if (!previewUrl) return
    return () => URL.revokeObjectURL(previewUrl)
  }, [previewUrl])

  function selectFile(next: File | null) {
    setPreviewUrl(next && typeof URL.createObjectURL === 'function' ? URL.createObjectURL(next) : null)
    onFileChange(next)
  }

  function handleChange(event: React.ChangeEvent<HTMLInputElement>) {
    const selected = event.target.files?.[0] ?? null
    if (!selected) return
    const message = photoError(selected)
    if (message) {
      setLocalError(message)
      selectFile(null)
      event.target.value = ''
      return
    }
    setLocalError(null)
    selectFile(selected)
  }

  function handleRemove() {
    setLocalError(null)
    if (file) {
      selectFile(null)
      if (inputRef.current) inputRef.current.value = ''
    } else {
      onRemovedChange?.(true)
    }
  }

  const shownError = localError ?? error
  const showCurrent = !file && Boolean(currentUrl) && !removed
  const hasImage = Boolean(previewUrl) || showCurrent

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>
        Foto <span className="font-normal text-muted-foreground">(opcional)</span>
      </Label>
      <div className="flex items-start gap-3">
        <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted">
          {previewUrl ? (
            <img src={previewUrl} alt="Vista previa de la foto" className="size-full object-cover" />
          ) : showCurrent ? (
            <img src={currentUrl!} alt="Foto actual" className="size-full object-cover" />
          ) : (
            <ImageIcon className="size-6 text-muted-foreground" aria-hidden />
          )}
        </div>
        <div className="flex min-w-0 flex-1 flex-col gap-2">
          <input
            ref={inputRef}
            id={id}
            type="file"
            accept={PHOTO_TYPES.join(',')}
            onChange={handleChange}
            aria-invalid={shownError ? true : undefined}
            aria-describedby={shownError ? `${id}-error` : `${id}-description`}
            className="w-full max-w-full text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-background file:px-2.5 file:py-1 file:text-sm file:font-medium"
          />
          {hasImage && (
            <div>
              <Button type="button" size="sm" variant="outline" onClick={handleRemove}>
                <XIcon data-icon="inline-start" />
                Quitar foto
              </Button>
            </div>
          )}
          {shownError ? (
            <p id={`${id}-error`} className="text-xs text-destructive">
              {shownError}
            </p>
          ) : (
            <p id={`${id}-description`} className="text-xs text-muted-foreground">
              JPEG, PNG o WebP, máximo 4 MB.
            </p>
          )}
        </div>
      </div>
    </div>
  )
}
