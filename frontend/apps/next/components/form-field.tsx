import { Label } from '@/components/ui/label'

// Etiqueta + control + mensaje de error/ayuda con los ids que necesita el
// control para `aria-describedby` (`${id}-error`, `${id}-description`).
export function FormField({
  id,
  label,
  error,
  description,
  children,
}: {
  id: string
  label: React.ReactNode
  error?: string
  description?: React.ReactNode
  children: React.ReactNode
}) {
  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {description && !error && (
        <p id={`${id}-description`} className="text-xs text-muted-foreground">
          {description}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}

export function fieldA11yProps(id: string, error?: string, hasDescription = false) {
  return {
    id,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': error ? `${id}-error` : hasDescription ? `${id}-description` : undefined,
  }
}
