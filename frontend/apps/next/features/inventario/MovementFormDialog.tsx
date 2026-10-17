'use client'

import { useEffect, useState } from 'react'
import { TriangleAlertIcon } from 'lucide-react'
import { inventoryApi, type StockRow } from 'app/features/inventario/api'
import {
  ADJUSTMENT_DIRECTIONS,
  ADJUSTMENT_DIRECTION_LABELS,
  MANUAL_MOVEMENT_TYPES,
  MOVEMENT_TYPE_LABELS,
  movementFormSchema,
  type ManualMovementType,
  type MovementValues,
} from 'app/features/inventario/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { TEXTAREA_CLASS } from '@/features/activos/AssetFormDialog'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { optionsPlaceholder } from '@/features/configuracion/useConfigurationOptions'
import { activeLocationItems, formatQuantity, quantityTypeItems, type FilterItem, type OptionsProps } from './shared'

const TYPE_ITEMS: FilterItem[] = MANUAL_MOVEMENT_TYPES.map((type) => ({ value: type, label: MOVEMENT_TYPE_LABELS[type] }))
const DIRECTION_ITEMS: FilterItem[] = ADJUSTMENT_DIRECTIONS.map((direction) => ({
  value: direction,
  label: ADJUSTMENT_DIRECTION_LABELS[direction],
}))

// Tamaño de página para traer las existencias de un tipo en todas sus ubicaciones.
const STOCK_PAGE_SIZE = 100

const TYPE_HELP: Record<ManualMovementType, string> = {
  entry: 'Ingreso a una ubicación (compra, donación).',
  exit: 'Sale del inventario (consumo, daño, pérdida). Requiere motivo.',
  transfer: 'Pasa de una ubicación a otra en una sola operación.',
  adjustment: 'Corrige la existencia de una ubicación. Requiere motivo.',
}

// Ubicaciones con existencia del tipo: origen de salidas, traslados y ajustes
// que disminuyen. Incluye ubicaciones inactivas (se pueden vaciar, D-003).
function originItems(rows: StockRow[]): FilterItem[] {
  return rows
    .filter((row) => row.quantity > 0)
    .map((row) => ({
      value: String(row.location.id),
      label: `${row.location.name} (${formatQuantity(row.quantity)} ${row.quantity === 1 ? 'disponible' : 'disponibles'})${
        row.location.is_active ? '' : ' · inactiva'
      }`,
    }))
}

// US1 / D-002: registrar entrada, salida, traslado o ajuste de un tipo por cantidad.
export function MovementFormDialog({
  open,
  onOpenChange,
  options,
  optionsFailed,
  onSaved,
}: OptionsProps & {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSaved: () => void
}) {
  const blocked = options !== null && quantityTypeItems(options).length === 0

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-xl">
        {/* Se monta de nuevo en cada apertura: el formulario arranca vacío. */}
        {open &&
          (blocked ? (
            <div className="flex flex-col gap-4">
              <DialogHeader>
                <DialogTitle>Registrar movimiento</DialogTitle>
                <DialogDescription className="flex items-start gap-2">
                  <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden />
                  <span>Primero crea un tipo de activo por cantidad activo en Configuración → Tipos de activo.</span>
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" onClick={() => onOpenChange(false)}>
                  Entendido
                </Button>
              </DialogFooter>
            </div>
          ) : (
            <MovementForm options={options} optionsFailed={optionsFailed} onCancel={() => onOpenChange(false)} onSaved={onSaved} />
          ))}
      </DialogContent>
    </Dialog>
  )
}

function MovementForm({
  options,
  optionsFailed,
  onCancel,
  onSaved,
}: OptionsProps & {
  onCancel: () => void
  onSaved: () => void
}) {
  const [type, setType] = useState<ManualMovementType | null>(null)
  const [assetTypeId, setAssetTypeId] = useState<string | null>(null)
  const [direction, setDirection] = useState<string | null>(null)
  // Existencias del tipo elegido (origen), guardadas con el tipo al que corresponden.
  const [stock, setStock] = useState<{ typeId: string; rows: StockRow[] | null; failed: boolean } | null>(null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    if (!assetTypeId) return
    let cancelled = false
    inventoryApi
      .stock({ assetTypeId: Number(assetTypeId), perPage: STOCK_PAGE_SIZE })
      .then(({ data }) => {
        if (!cancelled) setStock({ typeId: assetTypeId, rows: data, failed: false })
      })
      .catch(() => {
        if (!cancelled) setStock({ typeId: assetTypeId, rows: null, failed: true })
      })
    return () => {
      cancelled = true
    }
  }, [assetTypeId])

  const typeItems = quantityTypeItems(options)
  // D-003: entradas, traslados y ajustes que suman solo a ubicaciones activas.
  const destinationItems = activeLocationItems(options)
  const current = stock && stock.typeId === assetTypeId ? stock : null
  const stockRows = current?.rows ?? null
  const stockFailed = current?.failed ?? false
  const sources = stockRows ? originItems(stockRows) : []
  const sourcePlaceholder = !assetTypeId
    ? 'Primero elige el tipo de artículo'
    : stockFailed
      ? 'No se pudieron cargar las existencias'
      : stockRows === null
        ? 'Cargando…'
        : sources.length === 0
          ? 'Sin existencias de este tipo'
          : 'Selecciona el origen'
  const destinationPlaceholder = optionsPlaceholder(options, optionsFailed, 'Selecciona el destino')

  const reasonRequired = type === 'exit' || type === 'adjustment'
  // Los selects dependientes se reinician al cambiar clase, tipo o dirección.
  const dependentKey = `${type}-${assetTypeId}-${direction}`

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const id = (name: string) => {
      const value = String(formData.get(name) ?? '')
      return value === '' ? null : Number(value)
    }
    const raw = {
      type: type ?? undefined,
      asset_type_id: assetTypeId ? Number(assetTypeId) : undefined,
      quantity: String(formData.get('quantity') ?? ''),
      from_location_id: id('from_location_id'),
      to_location_id: id('to_location_id'),
      location_id: id('location_id'),
      direction: (direction as 'increase' | 'decrease' | null) ?? null,
      reason: String(formData.get('reason') ?? ''),
    }

    const parsed = movementFormSchema.safeParse(raw)
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      await inventoryApi.movements.create(parsed.data as MovementValues)
      onSaved()
    } catch (err) {
      if (err instanceof ApiValidationError) {
        // D-001: "Existencia insuficiente" llega junto a la cantidad. En el
        // ajuste, origen/destino del servidor se muestran en su única ubicación.
        const fieldErrors = apiFieldErrors(err)
        if (type === 'adjustment') {
          const locationError = fieldErrors.from_location_id ?? fieldErrors.to_location_id
          if (locationError) fieldErrors.location_id = locationError
        }
        setErrors(fieldErrors)
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible registrar el movimiento.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  const originField = (name: string, label: string) => (
    <CatalogSelectField
      key={`${name}-${dependentKey}`}
      id={`movement-${name}`}
      name={name}
      label={label}
      placeholder={sourcePlaceholder}
      items={sources}
      defaultValue={null}
      error={errors[name]}
    />
  )
  const destinationField = (name: string, label: string) => (
    <CatalogSelectField
      key={`${name}-${dependentKey}`}
      id={`movement-${name}`}
      name={name}
      label={label}
      placeholder={destinationPlaceholder}
      items={destinationItems}
      defaultValue={null}
      error={errors[name]}
      description="Solo ubicaciones activas."
    />
  )

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
      <DialogHeader>
        <DialogTitle>Registrar movimiento</DialogTitle>
        <DialogDescription>
          Artículos controlados por cantidad. Los movimientos no se editan ni se borran: un error se corrige con un movimiento inverso.
        </DialogDescription>
      </DialogHeader>

      <div className="grid gap-4 sm:grid-cols-2">
        <CatalogSelectField
          id="movement-type"
          name="type"
          label="Clase de movimiento"
          placeholder="Selecciona la clase"
          items={TYPE_ITEMS}
          defaultValue={null}
          error={errors.type}
          description={type ? TYPE_HELP[type] : undefined}
          onValueChange={(value) => {
            setType(value as ManualMovementType | null)
            setDirection(null)
          }}
        />
        <CatalogSelectField
          id="movement-asset-type"
          name="asset_type_id"
          label="Tipo de artículo"
          placeholder={optionsPlaceholder(options, optionsFailed, 'Selecciona un tipo')}
          items={typeItems}
          defaultValue={null}
          error={errors.asset_type_id}
          onValueChange={setAssetTypeId}
        />
        <FormField id="movement-quantity" label="Cantidad" error={errors.quantity}>
          <Input
            {...fieldA11yProps('movement-quantity', errors.quantity)}
            name="quantity"
            type="number"
            inputMode="numeric"
            min={1}
            step={1}
            required
          />
        </FormField>

        {type === 'adjustment' && (
          <CatalogSelectField
            id="movement-direction"
            name="direction"
            label="Dirección del ajuste"
            placeholder="Aumentar o disminuir"
            items={DIRECTION_ITEMS}
            defaultValue={null}
            error={errors.direction}
            onValueChange={setDirection}
          />
        )}

        {type === 'entry' && destinationField('to_location_id', 'Ubicación de destino')}
        {type === 'exit' && originField('from_location_id', 'Ubicación de origen')}
        {type === 'transfer' && (
          <>
            {originField('from_location_id', 'Ubicación de origen')}
            {destinationField('to_location_id', 'Ubicación de destino')}
          </>
        )}
        {type === 'adjustment' &&
          direction &&
          (direction === 'increase' ? destinationField('location_id', 'Ubicación') : originField('location_id', 'Ubicación'))}
      </div>

      <FormField
        id="movement-reason"
        label={
          reasonRequired ? (
            'Motivo'
          ) : (
            <>
              Motivo <span className="font-normal text-muted-foreground">(opcional)</span>
            </>
          )
        }
        error={errors.reason}
      >
        <textarea {...fieldA11yProps('movement-reason', errors.reason)} name="reason" maxLength={500} rows={2} className={TEXTAREA_CLASS} />
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
          {isSubmitting ? 'Registrando…' : 'Registrar'}
        </Button>
      </DialogFooter>
    </form>
  )
}
