'use client'

import { useState } from 'react'
import type { z } from 'zod'
import { TriangleAlertIcon } from 'lucide-react'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

// Formulario común de los cuatro catálogos de Configuración: nombre (≤120) y
// descripción opcional (≤500), más los campos propios de cada catálogo
// (`extraFields` + `readExtraValues`). Valida con zod en el cliente y muestra
// los 422 del servidor junto al campo (FR-012). `record` = null crea.
export function CatalogFormDialog<V, T>({
  open,
  onOpenChange,
  idPrefix,
  title,
  description,
  record,
  schema,
  save,
  onSaved,
  extraFields,
  readExtraValues,
  blocked,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  /** Prefijo de los ids de los campos (p. ej. "category" → "category-name"). */
  idPrefix: string
  title: string
  description?: string
  record: { name: string; description: string | null } | null
  schema: z.ZodType<V>
  save: (values: V) => Promise<{ data: T }>
  onSaved: (record: T, created: boolean) => void
  extraFields?: (errors: FieldErrors) => React.ReactNode
  readExtraValues?: (formData: FormData) => Record<string, unknown>
  /** Si se indica, se muestra este aviso en lugar del formulario (p. ej. US3-1). */
  blocked?: React.ReactNode
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {/* Se monta de nuevo en cada apertura: el formulario arranca con los datos del registro. */}
        {open &&
          (blocked ? (
            <div className="flex flex-col gap-4">
              <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription className="flex items-start gap-2">
                  <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden />
                  <span>{blocked}</span>
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" onClick={() => onOpenChange(false)}>
                  Entendido
                </Button>
              </DialogFooter>
            </div>
          ) : (
            <CatalogForm
              idPrefix={idPrefix}
              title={title}
              description={description}
              record={record}
              schema={schema}
              save={save}
              onSaved={onSaved}
              extraFields={extraFields}
              readExtraValues={readExtraValues}
              onCancel={() => onOpenChange(false)}
            />
          ))}
      </DialogContent>
    </Dialog>
  )
}

function CatalogForm<V, T>({
  idPrefix,
  title,
  description,
  record,
  schema,
  save,
  onSaved,
  extraFields,
  readExtraValues,
  onCancel,
}: {
  idPrefix: string
  title: string
  description?: string
  record: { name: string; description: string | null } | null
  schema: z.ZodType<V>
  save: (values: V) => Promise<{ data: T }>
  onSaved: (record: T, created: boolean) => void
  extraFields?: (errors: FieldErrors) => React.ReactNode
  readExtraValues?: (formData: FormData) => Record<string, unknown>
  onCancel: () => void
}) {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const nameId = `${idPrefix}-name`
  const descriptionId = `${idPrefix}-description`

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const parsed = schema.safeParse({
      name: formData.get('name') ?? '',
      description: formData.get('description') ?? '',
      ...readExtraValues?.(formData),
    })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      const { data } = await save(parsed.data)
      onSaved(data, record === null)
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setErrors(apiFieldErrors(err))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible guardar los cambios.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        {description && <DialogDescription>{description}</DialogDescription>}
      </DialogHeader>
      <FormField id={nameId} label="Nombre" error={errors.name}>
        <Input {...fieldA11yProps(nameId, errors.name)} name="name" defaultValue={record?.name ?? ''} maxLength={120} required />
      </FormField>
      {extraFields?.(errors)}
      <FormField
        id={descriptionId}
        label={
          <>
            Descripción <span className="font-normal text-muted-foreground">(opcional)</span>
          </>
        }
        error={errors.description}
      >
        <textarea
          {...fieldA11yProps(descriptionId, errors.description)}
          name="description"
          defaultValue={record?.description ?? ''}
          maxLength={500}
          rows={3}
          className="w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:bg-input/30"
        />
      </FormField>
      {formError && (
        <p role="alert" className="text-sm text-destructive">
          {formError}
        </p>
      )}
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Guardando…' : 'Guardar'}
        </Button>
      </DialogFooter>
    </form>
  )
}

export type SelectFieldItem = { value: string; label: string }

// Select de Base UI para un campo del formulario: guarda su valor en un input
// oculto con `name`, así el formulario lo lee del FormData como los demás.
export function CatalogSelectField({
  id,
  name,
  label,
  placeholder,
  items,
  defaultValue,
  error,
  description,
}: {
  id: string
  name: string
  label: string
  placeholder: string
  items: SelectFieldItem[]
  defaultValue: string | null
  error?: string
  description?: React.ReactNode
}) {
  const [value, setValue] = useState<string | null>(defaultValue)
  const labelId = `${id}-label`
  const errorId = `${id}-error`
  const descriptionId = `${id}-description`

  return (
    <div className="flex flex-col gap-2">
      <Label id={labelId}>{label}</Label>
      <Select items={items} value={value} onValueChange={(next) => setValue(next as string | null)}>
        <SelectTrigger
          className="w-full"
          aria-labelledby={labelId}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : description ? descriptionId : undefined}
        >
          <SelectValue placeholder={placeholder} />
        </SelectTrigger>
        <SelectContent>
          {items.map((item) => (
            <SelectItem key={item.value} value={item.value}>
              {item.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <input type="hidden" name={name} value={value ?? ''} />
      {description && !error && (
        <p id={descriptionId} className="text-xs text-muted-foreground">
          {description}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}
