'use client'

import { useState } from 'react'
import { deliveriesApi, type DeliveryItemRecord, type DeliveryRecord } from 'app/features/entregas/api'
import {
  RETURN_CONDITIONS,
  RETURN_CONDITION_LABELS,
  NOTES_MAX,
  returnFormSchema,
  returnNeedsLocation,
  type ReturnCondition,
  type ReturnFormRow,
} from 'app/features/entregas/schemas'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import { ApiValidationError } from 'app/lib/api-client'
import type { FieldErrors } from 'app/lib/form-errors'
import { fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { TEXTAREA_CLASS } from '@/features/activos/AssetFormDialog'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { optionsPlaceholder } from '@/features/configuracion/useConfigurationOptions'
import { activeLocationItems } from '@/features/inventario/shared'
import { cn } from '@/lib/utils'
import { formatQuantity, itemLabel } from './shared'

// D-004 / RN-026 / RN-038: qué pasa con lo devuelto según el estado.
function effectText(serialized: boolean, condition: ReturnCondition): string {
  if (serialized) {
    switch (condition) {
      case 'good':
        return 'Queda Disponible en la ubicación elegida y sin empleado.'
      case 'damaged':
        return 'Queda En mantenimiento en la ubicación elegida.'
      case 'lost':
        return 'Se da de baja con la observación. Es definitivo.'
    }
  }
  return condition === 'good' ? 'Vuelve a la existencia de la ubicación elegida.' : 'No vuelve al inventario: se registra como pérdida.'
}

type RowState = Omit<ReturnFormRow, 'quantity'> & { quantity: string; label: string }

function initialRows(items: DeliveryItemRecord[]): RowState[] {
  return items
    .filter((item) => item.pending_quantity > 0)
    .map((item) => ({
      delivery_item_id: item.id,
      serialized: item.asset !== null,
      pending_quantity: item.pending_quantity,
      quantity: '',
      condition: null,
      to_location_id: null,
      notes: '',
      label: itemLabel(item),
    }))
}

// US2: devolución total o parcial por ítems pendientes (FR-003).
export function ReturnDialog({
  open,
  onOpenChange,
  delivery,
  options,
  optionsFailed,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  delivery: DeliveryRecord
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onSaved: () => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        {/* Se monta de nuevo en cada apertura: arranca con los pendientes actuales. */}
        {open && (
          <ReturnForm delivery={delivery} options={options} optionsFailed={optionsFailed} onCancel={() => onOpenChange(false)} onSaved={onSaved} />
        )}
      </DialogContent>
    </Dialog>
  )
}

function ReturnForm({
  delivery,
  options,
  optionsFailed,
  onCancel,
  onSaved,
}: {
  delivery: DeliveryRecord
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onCancel: () => void
  onSaved: () => void
}) {
  const [rows, setRows] = useState<RowState[]>(() => initialRows(delivery.items))
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  // D-003 de 006: lo que vuelve, solo a ubicaciones activas.
  const locationItems = activeLocationItems(options)
  const locationPlaceholder = optionsPlaceholder(options, optionsFailed, 'Selecciona la ubicación')

  function update(index: number, patch: Partial<RowState>) {
    setRows((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)
    // z.object descarta la clave `label` (solo es para pintar).
    const parsed = returnFormSchema.safeParse({ items: rows })
    if (!parsed.success) {
      const found: FieldErrors = {}
      for (const issue of parsed.error.issues) found[issue.path.join('.')] ??= issue.message
      setErrors(found)
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      await deliveriesApi.createReturn(delivery.id, parsed.data)
      onSaved()
    } catch (err) {
      if (err instanceof ApiValidationError) {
        // El payload omite los renglones sin cantidad: "items.N" del servidor
        // se traduce al índice del renglón en pantalla.
        const sentIndexes = rows.flatMap((row, index) => (row.quantity.trim() === '' || row.quantity.trim() === '0' ? [] : [index]))
        const found: FieldErrors = {}
        for (const [field, messages] of Object.entries(err.errors)) {
          const match = /^items\.(\d+)(\..+)?$/.exec(field)
          const key = match ? `items.${sentIndexes[Number(match[1])] ?? match[1]}${match[2] ?? ''}` : field
          if (messages[0]) found[key] = messages[0]
        }
        setErrors(found)
        if (Object.keys(found).length === 0) setFormError(err.message)
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible registrar la devolución.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
      <DialogHeader>
        <DialogTitle>Registrar devolución</DialogTitle>
        <DialogDescription>
          {delivery.code} · {delivery.employee.full_name}. Marca lo que se devuelve y su estado. Las devoluciones no se editan ni se borran.
        </DialogDescription>
      </DialogHeader>

      <div className="flex flex-col gap-3">
        {rows.map((row, index) => {
          const prefix = `return-${row.delivery_item_id}`
          const error = (field: string) => errors[`items.${index}.${field}`]
          const selected = row.quantity.trim() !== '' && row.quantity.trim() !== '0'
          const needsLocation = returnNeedsLocation(row.serialized, row.condition)
          return (
            <fieldset key={row.delivery_item_id} className={cn('flex flex-col gap-3 rounded-lg border p-3', selected && 'border-primary/50')}>
              <legend className="px-1 text-sm font-medium">{row.label}</legend>

              {row.serialized ? (
                <label className="flex w-fit items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={selected}
                    onChange={(event) => update(index, { quantity: event.target.checked ? '1' : '' })}
                    className="size-4 accent-primary"
                  />
                  Devolver
                </label>
              ) : (
                <div className="flex flex-wrap items-end gap-3">
                  <div className="flex flex-col gap-1">
                    <Label htmlFor={`${prefix}-quantity`}>Cantidad a devolver</Label>
                    <Input
                      {...fieldA11yProps(`${prefix}-quantity`, error('quantity'))}
                      type="number"
                      inputMode="numeric"
                      min={0}
                      max={row.pending_quantity}
                      step={1}
                      value={row.quantity}
                      onChange={(event) => update(index, { quantity: event.target.value })}
                      className="w-28"
                    />
                  </div>
                  <span className="pb-2 text-xs text-muted-foreground">Pendiente: {formatQuantity(row.pending_quantity)}</span>
                </div>
              )}
              {error('quantity') && (
                <p id={`${prefix}-quantity-error`} className="text-xs text-destructive">
                  {error('quantity')}
                </p>
              )}

              {selected && (
                <>
                  <fieldset className="flex flex-col gap-1.5">
                    <legend className="mb-1 text-xs text-muted-foreground">Estado</legend>
                    <div className="flex flex-wrap gap-3">
                      {RETURN_CONDITIONS.map((condition) => (
                        <label key={condition} className="flex items-center gap-1.5 text-sm">
                          <input
                            type="radio"
                            name={`${prefix}-condition`}
                            value={condition}
                            checked={row.condition === condition}
                            onChange={() => update(index, { condition })}
                            className="accent-primary"
                          />
                          {RETURN_CONDITION_LABELS[condition]}
                        </label>
                      ))}
                    </div>
                    {row.condition && <p className="text-xs text-muted-foreground">{effectText(row.serialized, row.condition)}</p>}
                    {error('condition') && <p className="text-xs text-destructive">{error('condition')}</p>}
                  </fieldset>

                  {needsLocation && (
                    <CatalogSelectField
                      id={`${prefix}-location`}
                      name={`${prefix}-location`}
                      label="Ubicación a la que vuelve"
                      placeholder={locationPlaceholder}
                      items={locationItems}
                      defaultValue={row.to_location_id ? String(row.to_location_id) : null}
                      error={error('to_location_id')}
                      onValueChange={(value) => update(index, { to_location_id: value ? Number(value) : null })}
                    />
                  )}

                  <div className="flex flex-col gap-1">
                    <Label htmlFor={`${prefix}-notes`}>
                      Observación{' '}
                      {row.condition !== 'lost' && <span className="font-normal text-muted-foreground">(opcional)</span>}
                    </Label>
                    <textarea
                      {...fieldA11yProps(`${prefix}-notes`, error('notes'))}
                      value={row.notes}
                      maxLength={NOTES_MAX}
                      rows={2}
                      onChange={(event) => update(index, { notes: event.target.value })}
                      className={TEXTAREA_CLASS}
                    />
                    {error('notes') && (
                      <p id={`${prefix}-notes-error`} className="text-xs text-destructive">
                        {error('notes')}
                      </p>
                    )}
                  </div>
                </>
              )}
            </fieldset>
          )
        })}
      </div>

      {errors.items && <p className="text-sm text-destructive">{errors.items}</p>}
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
          {isSubmitting ? 'Registrando…' : 'Registrar devolución'}
        </Button>
      </DialogFooter>
    </form>
  )
}
