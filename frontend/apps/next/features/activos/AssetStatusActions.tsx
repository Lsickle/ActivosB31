'use client'

import { useState } from 'react'
import { ArchiveXIcon, CircleCheckIcon, MapPinIcon, TriangleAlertIcon, WrenchIcon } from 'lucide-react'
import { toast } from 'sonner'
import { assetsApi, type AssetRecord } from 'app/features/activos/api'
import { locationChangeSchema, manualTransitions, statusChangeSchema } from 'app/features/activos/schemas'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { optionsPlaceholder } from '@/features/configuracion/useConfigurationOptions'
import { TEXTAREA_CLASS } from './AssetFormDialog'

const BLOCKED_HINT_ID = 'asset-actions-blocked'

// US2 / FR-003 / D-001: cambios manuales de estado y ubicación, y baja con
// motivo. Asignado solo lo ponen y quitan las Entregas; Dado de baja es definitivo.
export function AssetStatusActions({
  asset,
  options,
  optionsFailed,
  onChanged,
}: {
  asset: AssetRecord
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onChanged: () => void
}) {
  const [confirmTarget, setConfirmTarget] = useState<'maintenance' | 'available' | null>(null)
  const [isRetireOpen, setIsRetireOpen] = useState(false)
  const [isLocationOpen, setIsLocationOpen] = useState(false)

  const transitions = manualTransitions(asset.status)
  const isAssigned = asset.status === 'assigned'
  const isRetired = asset.status === 'retired'
  const disabledProps = isAssigned ? { disabled: true, 'aria-describedby': BLOCKED_HINT_ID } : {}

  async function handleConfirm(): Promise<boolean> {
    if (!confirmTarget) return true
    try {
      await assetsApi.updateStatus(asset.id, { status: confirmTarget, reason: null })
      toast.success(
        confirmTarget === 'maintenance' ? `Activo "${asset.plate}" enviado a mantenimiento.` : `Activo "${asset.plate}" disponible.`
      )
      onChanged()
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No fue posible cambiar el estado del activo.')
    }
    return true
  }

  return (
    <section aria-labelledby="asset-actions-title">
      <Card>
        <CardHeader>
          <CardTitle>
            <h2 id="asset-actions-title">Estado y ubicación</h2>
          </CardTitle>
          <CardDescription>Disponible ⇄ En mantenimiento. La baja exige un motivo y es definitiva.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {isRetired ? (
            <p className="text-sm text-muted-foreground">La baja es definitiva: este activo no admite cambios de estado ni de ubicación.</p>
          ) : (
            <>
              <div className="flex flex-wrap gap-2">
                {(isAssigned || transitions.includes('maintenance')) && (
                  <Button variant="outline" onClick={() => setConfirmTarget('maintenance')} {...disabledProps}>
                    <WrenchIcon data-icon="inline-start" />
                    Enviar a mantenimiento
                  </Button>
                )}
                {transitions.includes('available') && (
                  <Button variant="outline" onClick={() => setConfirmTarget('available')}>
                    <CircleCheckIcon data-icon="inline-start" />
                    Marcar disponible
                  </Button>
                )}
                <Button variant="outline" onClick={() => setIsLocationOpen(true)} {...disabledProps}>
                  <MapPinIcon data-icon="inline-start" />
                  Cambiar ubicación
                </Button>
                <Button variant="destructive" onClick={() => setIsRetireOpen(true)} {...disabledProps}>
                  <ArchiveXIcon data-icon="inline-start" />
                  Dar de baja
                </Button>
              </div>
              {isAssigned && (
                <p id={BLOCKED_HINT_ID} className="flex items-start gap-2 text-sm text-muted-foreground">
                  <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden />
                  <span>
                    Este activo está asignado a {asset.holder?.full_name ?? 'un empleado'}: para cambiar su estado o ubicación, primero debe
                    devolverse en Entregas.
                  </span>
                </p>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={confirmTarget !== null}
        onOpenChange={(open) => !open && setConfirmTarget(null)}
        title={
          confirmTarget === 'maintenance'
            ? `¿Enviar a mantenimiento el activo "${asset.plate}"?`
            : `¿Marcar disponible el activo "${asset.plate}"?`
        }
        description={
          confirmTarget === 'maintenance'
            ? 'Quedará En mantenimiento y no se ofrecerá para entregas hasta que se marque disponible.'
            : 'Volverá a ofrecerse para entregas.'
        }
        confirmLabel={confirmTarget === 'maintenance' ? 'Enviar a mantenimiento' : 'Marcar disponible'}
        onConfirm={handleConfirm}
      />

      <RetireDialog
        open={isRetireOpen}
        onOpenChange={setIsRetireOpen}
        asset={asset}
        onDone={() => {
          setIsRetireOpen(false)
          onChanged()
        }}
      />

      <LocationDialog
        open={isLocationOpen}
        onOpenChange={setIsLocationOpen}
        asset={asset}
        options={options}
        optionsFailed={optionsFailed}
        onDone={() => {
          setIsLocationOpen(false)
          onChanged()
        }}
      />
    </section>
  )
}

function useDialogSubmit() {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function run(action: () => Promise<void>) {
    setFormError(null)
    setErrors({})
    setIsSubmitting(true)
    try {
      await action()
    } catch (err) {
      if (err instanceof ApiValidationError) setErrors(apiFieldErrors(err))
      else setFormError(err instanceof Error ? err.message : 'No fue posible completar la acción.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return { errors, setErrors, formError, isSubmitting, run }
}

function RetireDialog({
  open,
  onOpenChange,
  asset,
  onDone,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  asset: AssetRecord
  onDone: () => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>{open && <RetireForm asset={asset} onDone={onDone} onCancel={() => onOpenChange(false)} />}</DialogContent>
    </Dialog>
  )
}

function RetireForm({ asset, onDone, onCancel }: { asset: AssetRecord; onDone: () => void; onCancel: () => void }) {
  const { errors, setErrors, formError, isSubmitting, run } = useDialogSubmit()

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const parsed = statusChangeSchema.safeParse({ status: 'retired', reason: String(formData.get('reason') ?? '') })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }
    await run(async () => {
      await assetsApi.updateStatus(asset.id, parsed.data)
      toast.success(`Activo "${asset.plate}" dado de baja.`)
      onDone()
    })
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>Dar de baja el activo {asset.plate}</DialogTitle>
        <DialogDescription className="flex items-start gap-2">
          <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-destructive" aria-hidden />
          <span>
            La baja es definitiva: el activo dejará de ofrecerse en entregas y alertas y no podrá volver a otro estado. Su historial se
            conserva.
          </span>
        </DialogDescription>
      </DialogHeader>
      <FormField id="retire-reason" label="Motivo de la baja" error={errors.reason}>
        <textarea
          {...fieldA11yProps('retire-reason', errors.reason)}
          name="reason"
          maxLength={500}
          rows={3}
          required
          className={TEXTAREA_CLASS}
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
        <Button type="submit" variant="destructive" disabled={isSubmitting}>
          {isSubmitting ? 'Procesando…' : 'Dar de baja'}
        </Button>
      </DialogFooter>
    </form>
  )
}

function LocationDialog({
  open,
  onOpenChange,
  asset,
  options,
  optionsFailed,
  onDone,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  asset: AssetRecord
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onDone: () => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {open && (
          <LocationForm asset={asset} options={options} optionsFailed={optionsFailed} onDone={onDone} onCancel={() => onOpenChange(false)} />
        )}
      </DialogContent>
    </Dialog>
  )
}

function LocationForm({
  asset,
  options,
  optionsFailed,
  onDone,
  onCancel,
}: {
  asset: AssetRecord
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onDone: () => void
  onCancel: () => void
}) {
  const { errors, setErrors, formError, isSubmitting, run } = useDialogSubmit()
  // Edge case: solo ubicaciones activas; la actual no se ofrece (no sería un cambio).
  const items = (options?.locations ?? [])
    .filter((location) => location.id !== asset.location?.id)
    .map((location) => ({ value: String(location.id), label: location.name }))

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const formData = new FormData(event.currentTarget)
    const parsed = locationChangeSchema.safeParse({ location_id: Number(formData.get('location_id')) || undefined })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }
    await run(async () => {
      await assetsApi.updateLocation(asset.id, parsed.data.location_id)
      toast.success(`Ubicación de "${asset.plate}" actualizada.`)
      onDone()
    })
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>Cambiar ubicación de {asset.plate}</DialogTitle>
        <DialogDescription>Ubicación actual: {asset.location?.name ?? 'sin ubicación'}.</DialogDescription>
      </DialogHeader>
      <CatalogSelectField
        id="asset-new-location"
        name="location_id"
        label="Nueva ubicación"
        placeholder={optionsPlaceholder(options, optionsFailed, 'Selecciona una ubicación')}
        items={items}
        defaultValue={null}
        error={errors.location_id}
        description={
          options !== null && items.length === 0 ? 'No hay otras ubicaciones activas. Créalas en Configuración → Ubicaciones.' : undefined
        }
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
          {isSubmitting ? 'Guardando…' : 'Guardar ubicación'}
        </Button>
      </DialogFooter>
    </form>
  )
}
