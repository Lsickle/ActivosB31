'use client'

import { useState } from 'react'
import { TriangleAlertIcon } from 'lucide-react'
import { toast } from 'sonner'
import { assetsApi, type AssetRecord } from 'app/features/activos/api'
import { assetFormSchema, assetUpdateSchema, type AssetFormValues } from 'app/features/activos/schemas'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import { todayIsoDate } from 'app/features/empleados/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { optionsPlaceholder } from '@/features/configuracion/useConfigurationOptions'
import { PhotoField } from './PhotoField'

// Valor del select de ubicación para "sin ubicación" (assets.location_id NULL).
const NO_LOCATION = 'none'

export const TEXTAREA_CLASS =
  'w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:bg-input/30'

// D-007 / RN-007 / RN-003: solo tipos serializados disponibles (activos y de
// categoría activa: así llegan en las opciones). Al editar se conserva el
// tipo actual aunque ya no esté disponible, marcado.
export function serializedTypeItems(
  options: ConfigurationOptions | null,
  current?: AssetRecord['asset_type'] | null
): { value: string; label: string }[] {
  const categories = new Map((options?.asset_categories ?? []).map((category) => [category.id, category.name]))
  const items = (options?.asset_types ?? [])
    .filter((type) => type.tracking_mode === 'serialized')
    .map((type) => {
      const category = categories.get(type.asset_category_id)
      return { value: String(type.id), label: category ? `${type.name} (${category})` : type.name }
    })
  if (current && !items.some((item) => item.value === String(current.id))) {
    items.push({ value: String(current.id), label: `${current.name} (no disponible)` })
  }
  return items
}

function optionalLabel(text: string) {
  return (
    <>
      {text} <span className="font-normal text-muted-foreground">(opcional)</span>
    </>
  )
}

// US1 / FR-001 / FR-002: registrar o editar un activo serializado. `asset` = null crea.
export function AssetFormDialog({
  open,
  onOpenChange,
  asset,
  options,
  optionsFailed,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  asset: AssetRecord | null
  /** null mientras cargan o si fallaron (`optionsFailed`). */
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onSaved: (asset: AssetRecord, created: boolean) => void
}) {
  const title = asset ? `Editar activo ${asset.plate}` : 'Nuevo activo'
  const blocked = !asset && options !== null && serializedTypeItems(options).length === 0

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        {/* Se monta de nuevo en cada apertura: el formulario arranca con los datos del activo. */}
        {open &&
          (blocked ? (
            <div className="flex flex-col gap-4">
              <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription className="flex items-start gap-2">
                  <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden />
                  <span>Primero crea un tipo de activo serializado activo en Configuración → Tipos de activo.</span>
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" onClick={() => onOpenChange(false)}>
                  Entendido
                </Button>
              </DialogFooter>
            </div>
          ) : (
            <AssetForm
              title={title}
              asset={asset}
              options={options}
              optionsFailed={optionsFailed}
              onCancel={() => onOpenChange(false)}
              onSaved={onSaved}
            />
          ))}
      </DialogContent>
    </Dialog>
  )
}

function AssetForm({
  title,
  asset,
  options,
  optionsFailed,
  onCancel,
  onSaved,
}: {
  title: string
  asset: AssetRecord | null
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onCancel: () => void
  onSaved: (asset: AssetRecord, created: boolean) => void
}) {
  const isCreating = asset === null
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoRemoved, setPhotoRemoved] = useState(false)

  const typeItems = serializedTypeItems(options, asset?.asset_type)
  // "Mover un activo a una ubicación inactiva: no se ofrece" (edge case).
  const locationItems = [
    { value: NO_LOCATION, label: 'Sin ubicación' },
    ...(options?.locations ?? []).map((location) => ({ value: String(location.id), label: location.name })),
  ]

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const text = (name: string) => String(formData.get(name) ?? '')
    const locationValue = text('location_id')
    const raw = {
      asset_type_id: Number(text('asset_type_id')) || undefined,
      plate: text('plate'),
      serial: text('serial'),
      brand: text('brand'),
      model: text('model'),
      acquisition_date: text('acquisition_date'),
      acquisition_value: text('acquisition_value'),
      useful_life_months: text('useful_life_months'),
      expires_on: text('expires_on'),
      location_id: locationValue === '' || locationValue === NO_LOCATION ? null : Number(locationValue),
      notes: text('notes'),
    }

    const parsed = isCreating ? assetFormSchema.safeParse(raw) : assetUpdateSchema.safeParse(raw)
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      if (isCreating) {
        const { data } = await assetsApi.create(parsed.data as AssetFormValues, photo)
        onSaved(data, true)
        return
      }

      const { data } = await assetsApi.update(asset.id, parsed.data)
      // La foto va por su propio endpoint; si falla, los datos ya quedaron guardados.
      try {
        if (photo) await assetsApi.uploadPhoto(asset.id, photo)
        else if (photoRemoved && asset.photo_url) await assetsApi.deletePhoto(asset.id)
      } catch (err) {
        const reason = err instanceof Error ? err.message : 'error inesperado'
        toast.error(`Los datos se guardaron, pero no fue posible actualizar la foto: ${reason}`)
      }
      onSaved(data, false)
    } catch (err) {
      if (err instanceof ApiValidationError) {
        // US1-2: placa duplicada (y demás reglas del servidor) junto al campo.
        setErrors(apiFieldErrors(err))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible guardar el activo.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>
          Bienes que se controlan uno a uno (serializados). Los artículos por cantidad se registran en Inventario.
        </DialogDescription>
      </DialogHeader>

      <div className="grid gap-4 sm:grid-cols-2">
        <CatalogSelectField
          id="asset-type"
          name="asset_type_id"
          label="Tipo de activo"
          placeholder={optionsPlaceholder(options, optionsFailed, 'Selecciona un tipo')}
          items={typeItems}
          defaultValue={asset ? String(asset.asset_type.id) : null}
          error={errors.asset_type_id}
        />
        <FormField id="asset-plate" label="Placa de inventario" error={errors.plate} description="Única. Se guarda en mayúsculas.">
          <Input
            {...fieldA11yProps('asset-plate', errors.plate, true)}
            name="plate"
            defaultValue={asset?.plate ?? ''}
            autoComplete="off"
            spellCheck={false}
            maxLength={60}
            className="uppercase"
            required
          />
        </FormField>
        <FormField id="asset-serial" label={optionalLabel('Serial del fabricante')} error={errors.serial}>
          <Input
            {...fieldA11yProps('asset-serial', errors.serial)}
            name="serial"
            defaultValue={asset?.serial ?? ''}
            autoComplete="off"
            spellCheck={false}
            maxLength={100}
          />
        </FormField>
        <FormField id="asset-brand" label={optionalLabel('Marca')} error={errors.brand}>
          <Input {...fieldA11yProps('asset-brand', errors.brand)} name="brand" defaultValue={asset?.brand ?? ''} maxLength={80} />
        </FormField>
        <FormField id="asset-model" label={optionalLabel('Modelo')} error={errors.model}>
          <Input {...fieldA11yProps('asset-model', errors.model)} name="model" defaultValue={asset?.model ?? ''} maxLength={80} />
        </FormField>
        <FormField id="asset-acquisition-date" label={optionalLabel('Fecha de adquisición')} error={errors.acquisition_date}>
          <Input
            {...fieldA11yProps('asset-acquisition-date', errors.acquisition_date)}
            name="acquisition_date"
            type="date"
            max={todayIsoDate()}
            defaultValue={asset?.acquisition_date ?? ''}
          />
        </FormField>
        <FormField id="asset-acquisition-value" label={optionalLabel('Valor de adquisición (COP)')} error={errors.acquisition_value}>
          <Input
            {...fieldA11yProps('asset-acquisition-value', errors.acquisition_value)}
            name="acquisition_value"
            type="number"
            inputMode="decimal"
            min={0}
            step="0.01"
            defaultValue={asset?.acquisition_value ?? ''}
          />
        </FormField>
        <FormField id="asset-useful-life" label={optionalLabel('Vida útil (meses)')} error={errors.useful_life_months}>
          <Input
            {...fieldA11yProps('asset-useful-life', errors.useful_life_months)}
            name="useful_life_months"
            type="number"
            inputMode="numeric"
            min={1}
            step={1}
            defaultValue={asset?.useful_life_months ?? ''}
          />
        </FormField>
        <FormField
          id="asset-expires-on"
          label={optionalLabel('Fecha de vencimiento')}
          error={errors.expires_on}
          description="Para EPP con vida útil: genera alertas de vencimiento."
        >
          <Input
            {...fieldA11yProps('asset-expires-on', errors.expires_on, true)}
            name="expires_on"
            type="date"
            defaultValue={asset?.expires_on ?? ''}
          />
        </FormField>
        {isCreating && (
          <CatalogSelectField
            id="asset-location"
            name="location_id"
            label="Ubicación"
            placeholder={optionsPlaceholder(options, optionsFailed, 'Sin ubicación')}
            items={locationItems}
            defaultValue={NO_LOCATION}
            error={errors.location_id}
          />
        )}
      </div>

      <FormField id="asset-notes" label={optionalLabel('Observaciones')} error={errors.notes}>
        <textarea
          {...fieldA11yProps('asset-notes', errors.notes)}
          name="notes"
          defaultValue={asset?.notes ?? ''}
          maxLength={1000}
          rows={3}
          className={TEXTAREA_CLASS}
        />
      </FormField>

      <PhotoField
        id="asset-photo"
        file={photo}
        onFileChange={setPhoto}
        currentUrl={asset?.photo_url}
        removed={photoRemoved}
        onRemovedChange={setPhotoRemoved}
        error={errors.photo}
      />

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
          {isSubmitting ? 'Guardando…' : isCreating ? 'Registrar activo' : 'Guardar'}
        </Button>
      </DialogFooter>
    </form>
  )
}
