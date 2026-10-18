'use client'
/* eslint-disable @next/next/no-img-element -- vista previa de la firma (data URL PNG): next/image no aplica. */

import { useEffect, useMemo, useState } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { ArrowLeftIcon, CheckIcon, PackageIcon, PlusIcon, SearchIcon, Trash2Icon, TriangleAlertIcon } from 'lucide-react'
import { toast } from 'sonner'
import { assetsApi } from 'app/features/activos/api'
import { employeesOptions, type EmployeeOption } from 'app/features/empleados/api'
import { todayIsoDate } from 'app/features/empleados/schemas'
import { deliveriesApi, type AssetForDelivery } from 'app/features/entregas/api'
import { DELIVERY_KIND_LABELS, deliveryFormSchema, NOTES_MAX, type DeliveryKind } from 'app/features/entregas/schemas'
import { inventoryApi } from 'app/features/inventario/api'
import { ApiValidationError } from 'app/lib/api-client'
import type { FieldErrors } from 'app/lib/form-errors'
import { useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { SignaturePad } from '@/components/signature-pad'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { TEXTAREA_CLASS } from '@/features/activos/AssetFormDialog'
import { formatDate } from '@/features/activos/format'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { optionsPlaceholder, useConfigurationOptions } from '@/features/configuracion/useConfigurationOptions'
import { activeLocationItems, quantityTypeItems } from '@/features/inventario/shared'
import { cn } from '@/lib/utils'
import { formatQuantity, NOTES_PRIVACY_HINT } from './shared'

// Asistente "Nueva entrega" (spec 007 US1; FR-001, FR-002; RN-023..RN-025,
// RN-039; D-003). Pasos: empleado → tipo y fecha → origen → artículos → firma
// → confirmar. La entrega no se puede editar ni borrar después (FR-001).

const STEPS = ['Empleado', 'Tipo y fecha', 'Origen', 'Artículos', 'Firma', 'Confirmar'] as const

// Campos que valida cada paso (claves del esquema / del 422 del servidor).
const STEP_FIELDS: string[][] = [['employee_id'], ['kind', 'due_on', 'notes'], ['from_location_id'], ['items'], ['signature'], []]

const SEARCH_DEBOUNCE_MS = 300
const ASSET_RESULTS = 10

type AssetLine = { key: string; type: 'asset'; assetId: number; label: string; detail: string }
type QuantityLine = { key: string; type: 'quantity'; assetTypeId: number; label: string; quantity: number }
type Line = AssetLine | QuantityLine

function fieldStep(field: string): number {
  const index = STEP_FIELDS.findIndex((fields) => fields.some((name) => field === name || field.startsWith(`${name}.`)))
  return index === -1 ? STEPS.length - 1 : index
}

function normalize(text: string): string {
  return text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
}

export function NewDeliveryScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('entregas.gestionar')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return <NewDeliveryWizard />
}

function NewDeliveryWizard() {
  const router = useRouter()
  const { options, failed: optionsFailed } = useConfigurationOptions()

  const [step, setStep] = useState(0)
  const [maxStep, setMaxStep] = useState(0)
  const [employees, setEmployees] = useState<EmployeeOption[] | null>(null)
  const [employeesFailed, setEmployeesFailed] = useState(false)

  const [employeeId, setEmployeeId] = useState<number | null>(null)
  const [kind, setKind] = useState<DeliveryKind | null>(null)
  const [dueOn, setDueOn] = useState('')
  const [notes, setNotes] = useState('')
  const [fromLocationId, setFromLocationId] = useState<number | null>(null)
  const [lines, setLines] = useState<Line[]>([])
  const [signature, setSignature] = useState<string | null>(null)
  const [signatureCleared, setSignatureCleared] = useState(false)
  const [originNotice, setOriginNotice] = useState(false)

  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    let cancelled = false
    employeesOptions()
      .then(({ data }) => {
        if (!cancelled) setEmployees(data)
      })
      .catch(() => {
        if (!cancelled) setEmployeesFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const employee = employees?.find((option) => option.id === employeeId) ?? null
  const locationName = options?.locations.find((location) => location.id === fromLocationId)?.name ?? null

  // La firma cubre lo que se ve al firmar: si cambia algo de la entrega, se pide de nuevo.
  function invalidateSignature() {
    if (signature) {
      setSignature(null)
      setSignatureCleared(true)
    }
  }

  function raw() {
    return {
      employee_id: employeeId,
      kind,
      due_on: dueOn,
      from_location_id: fromLocationId,
      notes,
      signature,
      items: lines.map((line) =>
        line.type === 'asset' ? { asset_id: line.assetId } : { asset_type_id: line.assetTypeId, quantity: line.quantity }
      ),
    }
  }

  function validate(): { errors: FieldErrors; payload: ReturnType<ReturnType<typeof deliveryFormSchema>['parse']> | null } {
    const result = deliveryFormSchema(todayIsoDate()).safeParse(raw())
    if (result.success) return { errors: {}, payload: result.data }
    const found: FieldErrors = {}
    for (const issue of result.error.issues) {
      found[issue.path.join('.')] ??= issue.message
    }
    return { errors: found, payload: null }
  }

  function goTo(target: number) {
    setStep(target)
    setMaxStep((current) => Math.max(current, target))
  }

  function handleNext() {
    const { errors: found } = validate()
    const stepErrors = Object.fromEntries(Object.entries(found).filter(([field]) => fieldStep(field) === step))
    setErrors(stepErrors)
    if (Object.keys(stepErrors).length === 0) goTo(step + 1)
  }

  async function handleConfirm() {
    setFormError(null)
    const { errors: found, payload } = validate()
    if (!payload) {
      setErrors(found)
      setStep(Math.min(...Object.keys(found).map(fieldStep)))
      return
    }
    setIsSubmitting(true)
    try {
      const { data } = await deliveriesApi.create(payload)
      toast.success(`Entrega ${data.code} registrada.`)
      router.push(`/entregas/${data.id}`)
    } catch (err) {
      if (err instanceof ApiValidationError) {
        const serverErrors: FieldErrors = {}
        for (const [field, messages] of Object.entries(err.errors)) {
          if (messages[0]) serverErrors[field] = messages[0]
        }
        setErrors(serverErrors)
        const fields = Object.keys(serverErrors)
        if (fields.length > 0) setStep(Math.min(...fields.map(fieldStep)))
        if (fields.length === 0) setFormError(err.message)
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible registrar la entrega.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="flex flex-col gap-4">
      <Link href="/entregas" className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeftIcon className="size-4" aria-hidden />
        Entregas
      </Link>
      <div>
        <h1 className="text-xl font-semibold">Nueva entrega</h1>
        <p className="text-sm text-muted-foreground">Dotación o préstamo de activos a un empleado, con su firma. Una vez registrada no se puede editar ni borrar.</p>
      </div>

      <ol aria-label="Pasos de la entrega" className="flex flex-wrap gap-2">
        {STEPS.map((label, index) => (
          <li key={label} aria-current={index === step ? 'step' : undefined}>
            <button
              type="button"
              disabled={index > maxStep}
              onClick={() => setStep(index)}
              className={cn(
                'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs transition-colors disabled:opacity-50',
                index === step ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'
              )}
            >
              <span className="tabular-nums" aria-hidden>
                {index < maxStep && index !== step ? <CheckIcon className="size-3" /> : index + 1}
              </span>
              {label}
            </button>
          </li>
        ))}
      </ol>

      <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
        <Card>
          <CardContent className="flex flex-col gap-5">
            {step === 0 && (
              <EmployeeStep
                employees={employees}
                failed={employeesFailed}
                value={employeeId}
                error={errors.employee_id}
                onChange={(id) => {
                  setEmployeeId(id)
                  invalidateSignature()
                }}
              />
            )}
            {step === 1 && (
              <KindStep
                kind={kind}
                dueOn={dueOn}
                notes={notes}
                errors={errors}
                onKind={(value) => {
                  setKind(value)
                  invalidateSignature()
                }}
                onDueOn={(value) => {
                  setDueOn(value)
                  invalidateSignature()
                }}
                onNotes={setNotes}
              />
            )}
            {step === 2 && (
              <section aria-labelledby="origin-title" className="flex flex-col gap-4">
                <h2 id="origin-title" className="text-base font-medium">
                  Ubicación de origen
                </h2>
                <CatalogSelectField
                  id="delivery-origin"
                  name="from_location_id"
                  label="Ubicación de origen"
                  placeholder={optionsPlaceholder(options, optionsFailed, 'Selecciona la ubicación')}
                  items={activeLocationItems(options)}
                  defaultValue={fromLocationId ? String(fromLocationId) : null}
                  error={errors.from_location_id}
                  description="De aquí salen los artículos por cantidad. Los activos serializados conservan su ubicación al entregarse (RN-039)."
                  onValueChange={(value) => {
                    const id = value ? Number(value) : null
                    if (id === fromLocationId) return
                    setFromLocationId(id)
                    // La existencia depende del origen: se quitan las cantidades ya agregadas.
                    if (lines.some((line) => line.type === 'quantity')) {
                      setLines((current) => current.filter((line) => line.type === 'asset'))
                      setOriginNotice(true)
                      invalidateSignature()
                    }
                  }}
                />
                {originNotice && (
                  <p role="status" className="text-xs text-muted-foreground">
                    Se quitaron los artículos por cantidad porque cambió la ubicación de origen.
                  </p>
                )}
              </section>
            )}
            {step === 3 && (
              <ItemsStep
                options={options}
                fromLocationId={fromLocationId}
                locationName={locationName}
                lines={lines}
                errors={errors}
                onAdd={(line) => {
                  setLines((current) => [...current, line])
                  setOriginNotice(false)
                  invalidateSignature()
                }}
                onRemove={(key) => {
                  setLines((current) => current.filter((line) => line.key !== key))
                  invalidateSignature()
                }}
              />
            )}
            {step === 4 && (
              <section aria-labelledby="signature-title" className="flex flex-col gap-3">
                <h2 id="signature-title" className="text-base font-medium">
                  Firma
                </h2>
                <p className="text-sm text-muted-foreground">
                  {employee?.full_name ?? 'El empleado'} firma que recibe {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'} como{' '}
                  {kind ? DELIVERY_KIND_LABELS[kind].toLowerCase() : 'entrega'}
                  {kind === 'prestamo' && dueOn ? `, con devolución esperada el ${formatDate(dueOn)}` : ''}.
                </p>
                {signature ? (
                  <div className="flex flex-col gap-2">
                    <img src={signature} alt={`Firma de ${employee?.full_name ?? 'el empleado'}`} className="h-48 w-full rounded-lg border bg-white object-contain" />
                    <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => setSignature(null)}>
                      Firmar de nuevo
                    </Button>
                  </div>
                ) : (
                  <SignaturePad
                    label={`Firma de ${employee?.full_name ?? 'el empleado'}`}
                    error={errors.signature}
                    onChange={(value) => {
                      setSignature(value)
                      if (value) {
                        setSignatureCleared(false)
                        setErrors((current) => {
                          const next = { ...current }
                          delete next.signature
                          return next
                        })
                      }
                    }}
                  />
                )}
              </section>
            )}
            {step === 5 && (
              <SummaryStep
                employee={employee}
                kind={kind}
                dueOn={dueOn}
                locationName={locationName}
                notes={notes}
                lines={lines}
                signature={signature}
              />
            )}

            {signatureCleared && !signature && (
              <p role="status" className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-500">
                <TriangleAlertIcon className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                La firma se borró porque cambiaron los datos de la entrega. El empleado debe firmar de nuevo.
              </p>
            )}

            {formError && (
              <p role="alert" className="text-sm text-destructive">
                {formError}
              </p>
            )}

            <div className="flex justify-between gap-2">
              <Button type="button" variant="outline" disabled={step === 0} onClick={() => setStep(step - 1)}>
                Anterior
              </Button>
              {step < STEPS.length - 1 ? (
                <Button type="button" onClick={handleNext}>
                  Siguiente
                </Button>
              ) : (
                <Button type="button" onClick={handleConfirm} disabled={isSubmitting}>
                  {isSubmitting ? 'Registrando…' : 'Confirmar entrega'}
                </Button>
              )}
            </div>
          </CardContent>
        </Card>

        <Card className="lg:sticky lg:top-4">
          <CardHeader>
            <CardTitle>Resumen</CardTitle>
            <CardDescription>Se actualiza con cada paso.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-2 text-sm">
            <p>{employee ? `${employee.full_name} · ${employee.position}` : <span className="text-muted-foreground">Sin empleado</span>}</p>
            <p className="text-muted-foreground">
              {kind ? DELIVERY_KIND_LABELS[kind] : 'Sin tipo'}
              {kind === 'prestamo' && dueOn ? ` · hasta ${formatDate(dueOn)}` : ''}
            </p>
            <p className="text-muted-foreground">{locationName ?? 'Sin origen'}</p>
            <p className="text-muted-foreground">
              {lines.length} {lines.length === 1 ? 'artículo' : 'artículos'} · {signature ? 'Firmada' : 'Sin firma'}
            </p>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

// ---- Paso 1: empleado (empleados activos, sin datos personales: D-006) ----

function EmployeeStep({
  employees,
  failed,
  value,
  error,
  onChange,
}: {
  employees: EmployeeOption[] | null
  failed: boolean
  value: number | null
  error?: string
  onChange: (id: number) => void
}) {
  const [search, setSearch] = useState('')
  const filtered = useMemo(() => {
    const term = normalize(search.trim())
    return (employees ?? []).filter((employee) => term === '' || normalize(`${employee.full_name} ${employee.position}`).includes(term))
  }, [employees, search])

  return (
    <fieldset className="flex flex-col gap-3" aria-describedby={error ? 'delivery-employee-error' : undefined}>
      <legend className="mb-2 text-base font-medium">Empleado que recibe</legend>
      <div className="relative">
        <SearchIcon className="pointer-events-none absolute top-2 left-2.5 size-4 text-muted-foreground" aria-hidden />
        <Input
          type="search"
          aria-label="Buscar empleado"
          placeholder="Buscar por nombre o cargo"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          className="pl-8"
        />
      </div>
      {failed ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar los empleados.
        </p>
      ) : employees === null ? (
        <p className="text-sm text-muted-foreground">Cargando empleados…</p>
      ) : filtered.length === 0 ? (
        <p className="text-sm text-muted-foreground">No hay empleados activos que coincidan.</p>
      ) : (
        <div className="flex max-h-72 flex-col gap-1 overflow-y-auto rounded-lg border p-1">
          {filtered.map((employee) => (
            <label
              key={employee.id}
              className={cn(
                'flex cursor-pointer items-center gap-3 rounded-md px-2.5 py-2 text-sm hover:bg-muted',
                value === employee.id && 'bg-muted'
              )}
            >
              <input
                type="radio"
                name="employee_id"
                value={employee.id}
                checked={value === employee.id}
                onChange={() => onChange(employee.id)}
                className="accent-primary"
              />
              <span className="font-medium">{employee.full_name}</span>
              <span className="ml-auto text-xs text-muted-foreground">{employee.position}</span>
            </label>
          ))}
        </div>
      )}
      <p className="text-xs text-muted-foreground">Solo empleados activos.</p>
      {error && (
        <p id="delivery-employee-error" className="text-xs text-destructive">
          {error}
        </p>
      )}
    </fieldset>
  )
}

// ---- Paso 2: tipo, fecha esperada y observaciones (RN-023) ----

const KIND_HELP: Record<DeliveryKind, string> = {
  dotacion: 'Asignación permanente al empleado.',
  prestamo: 'Préstamo temporal con fecha de devolución esperada; si vence con artículos pendientes, genera alerta.',
}

function KindStep({
  kind,
  dueOn,
  notes,
  errors,
  onKind,
  onDueOn,
  onNotes,
}: {
  kind: DeliveryKind | null
  dueOn: string
  notes: string
  errors: FieldErrors
  onKind: (kind: DeliveryKind) => void
  onDueOn: (value: string) => void
  onNotes: (value: string) => void
}) {
  return (
    <div className="flex flex-col gap-5">
      <fieldset className="flex flex-col gap-2" aria-describedby={errors.kind ? 'delivery-kind-error' : undefined}>
        <legend className="mb-2 text-base font-medium">Tipo de entrega</legend>
        <div className="grid gap-2 sm:grid-cols-2">
          {(['dotacion', 'prestamo'] as const).map((option) => (
            <label key={option} className={cn('flex cursor-pointer gap-3 rounded-lg border p-3 text-sm', kind === option && 'border-primary bg-muted/50')}>
              <input type="radio" name="kind" value={option} checked={kind === option} onChange={() => onKind(option)} className="mt-0.5 accent-primary" />
              <span className="flex flex-col gap-0.5">
                <span className="font-medium">{DELIVERY_KIND_LABELS[option]}</span>
                <span className="text-xs text-muted-foreground">{KIND_HELP[option]}</span>
              </span>
            </label>
          ))}
        </div>
        {errors.kind && (
          <p id="delivery-kind-error" className="text-xs text-destructive">
            {errors.kind}
          </p>
        )}
      </fieldset>

      {kind === 'prestamo' && (
        <FormField id="delivery-due-on" label="Fecha de devolución esperada" error={errors.due_on}>
          <Input
            {...fieldA11yProps('delivery-due-on', errors.due_on)}
            type="date"
            min={todayIsoDate()}
            value={dueOn}
            onChange={(event) => onDueOn(event.target.value)}
            className="sm:w-48"
          />
        </FormField>
      )}

      <FormField
        id="delivery-notes"
        label={
          <>
            Observaciones <span className="font-normal text-muted-foreground">(opcional)</span>
          </>
        }
        error={errors.notes}
        description={NOTES_PRIVACY_HINT}
      >
        <textarea
          {...fieldA11yProps('delivery-notes', errors.notes, true)}
          value={notes}
          maxLength={NOTES_MAX}
          rows={2}
          onChange={(event) => onNotes(event.target.value)}
          className={TEXTAREA_CLASS}
        />
      </FormField>
    </div>
  )
}

// ---- Paso 4: artículos (RN-025) ----

function ItemsStep({
  options,
  fromLocationId,
  locationName,
  lines,
  errors,
  onAdd,
  onRemove,
}: {
  options: Parameters<typeof quantityTypeItems>[0]
  fromLocationId: number | null
  locationName: string | null
  lines: Line[]
  errors: FieldErrors
  onAdd: (line: Line) => void
  onRemove: (key: string) => void
}) {
  // Errores del servidor por renglón (p. ej. "items.0.asset_id").
  const lineError = (index: number) =>
    errors[`items.${index}.asset_id`] ?? errors[`items.${index}.asset_type_id`] ?? errors[`items.${index}.quantity`] ?? errors[`items.${index}`]

  return (
    <section aria-labelledby="items-title" className="flex flex-col gap-5">
      <h2 id="items-title" className="text-base font-medium">
        Artículos
      </h2>
      <AssetSearch addedIds={new Set(lines.flatMap((line) => (line.type === 'asset' ? [line.assetId] : [])))} onAdd={onAdd} />
      <QuantityPicker
        options={options}
        fromLocationId={fromLocationId}
        locationName={locationName}
        excluded={new Set(lines.flatMap((line) => (line.type === 'quantity' ? [line.assetTypeId] : [])))}
        onAdd={onAdd}
      />

      {lines.length === 0 ? (
        <div className="flex flex-col items-center gap-1 rounded-lg border border-dashed py-8 text-center">
          <PackageIcon className="size-6 text-muted-foreground" aria-hidden />
          <p className="text-sm">Aún no hay artículos</p>
          <p className="text-xs text-muted-foreground">Agrega activos serializados o artículos por cantidad.</p>
        </div>
      ) : (
        <div className="rounded-lg border">
          <Table aria-label="Artículos de la entrega">
            <TableHeader>
              <TableRow>
                <TableHead scope="col">Artículo</TableHead>
                <TableHead scope="col">Cantidad</TableHead>
                <TableHead scope="col">
                  <span className="sr-only">Acciones</span>
                </TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.map((line, index) => (
                <TableRow key={line.key}>
                  <TableCell>
                    <div className="flex flex-col">
                      <span>{line.label}</span>
                      {line.type === 'asset' && <span className="text-xs text-muted-foreground">{line.detail}</span>}
                      {lineError(index) && <span className="text-xs text-destructive">{lineError(index)}</span>}
                    </div>
                  </TableCell>
                  <TableCell className="tabular-nums">{line.type === 'asset' ? 1 : formatQuantity(line.quantity)}</TableCell>
                  <TableCell className="text-right">
                    <Button type="button" size="icon-sm" variant="ghost" aria-label={`Quitar ${line.label}`} title="Quitar" onClick={() => onRemove(line.key)}>
                      <Trash2Icon />
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
      {errors.items && <p className="text-xs text-destructive">{errors.items}</p>}
    </section>
  )
}

// Activos serializados disponibles (US1-4: no se ofrecen Asignados, En mantenimiento ni Dados de baja).
function AssetSearch({ addedIds, onAdd }: { addedIds: Set<number>; onAdd: (line: Line) => void }) {
  const [searchInput, setSearchInput] = useState('')
  const [results, setResults] = useState<{ term: string; assets: AssetForDelivery[] | null; failed: boolean } | null>(null)

  useEffect(() => {
    const term = searchInput.trim()
    if (term === '') return
    let cancelled = false
    const timeout = setTimeout(() => {
      assetsApi
        .list({ search: term, status: 'available', perPage: ASSET_RESULTS })
        .then(({ data }) => {
          if (!cancelled) setResults({ term, assets: data, failed: false })
        })
        .catch(() => {
          if (!cancelled) setResults({ term, assets: null, failed: true })
        })
    }, SEARCH_DEBOUNCE_MS)
    return () => {
      cancelled = true
      clearTimeout(timeout)
    }
  }, [searchInput])

  const term = searchInput.trim()
  const current = results && results.term === term ? results : null

  return (
    <div className="flex flex-col gap-2">
      <h3 className="text-sm font-medium">Activos serializados</h3>
      <Input
        type="search"
        aria-label="Buscar activo disponible por placa"
        placeholder="Buscar por placa o serial"
        value={searchInput}
        onChange={(event) => setSearchInput(event.target.value)}
      />
      {term === '' ? (
        <p className="text-xs text-muted-foreground">Solo se ofrecen activos Disponibles.</p>
      ) : current?.failed ? (
        <p role="alert" className="text-xs text-destructive">
          No fue posible buscar activos.
        </p>
      ) : !current ? (
        <p className="text-xs text-muted-foreground">Buscando…</p>
      ) : current.assets && current.assets.length === 0 ? (
        <p className="text-xs text-muted-foreground">No hay activos disponibles que coincidan.</p>
      ) : (
        <ul className="flex flex-col gap-1" aria-label="Activos disponibles">
          {current.assets?.map((asset) => {
            const added = addedIds.has(asset.id)
            return (
              <li key={asset.id} className="flex items-center justify-between gap-3 rounded-lg border px-2.5 py-1.5 text-sm">
                <div className="min-w-0">
                  <p className="font-medium tabular-nums">{asset.plate}</p>
                  <p className="truncate text-xs text-muted-foreground">
                    {asset.asset_type.name}
                    {asset.location ? ` · ${asset.location.name}` : ''}
                  </p>
                </div>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={added}
                  aria-label={added ? `${asset.plate} ya agregado` : `Agregar ${asset.plate}`}
                  onClick={() =>
                    onAdd({
                      key: `asset-${asset.id}`,
                      type: 'asset',
                      assetId: asset.id,
                      label: `${asset.asset_type.name} · ${asset.plate}`,
                      detail: asset.location ? `Ubicación: ${asset.location.name}` : 'Sin ubicación',
                    })
                  }
                >
                  {added ? 'Agregado' : 'Agregar'}
                </Button>
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

// Artículos por cantidad con la existencia visible en el origen (US1-5).
function QuantityPicker({
  options,
  fromLocationId,
  locationName,
  excluded,
  onAdd,
}: {
  options: Parameters<typeof quantityTypeItems>[0]
  fromLocationId: number | null
  locationName: string | null
  excluded: Set<number>
  onAdd: (line: Line) => void
}) {
  const [resetKey, setResetKey] = useState(0)
  const [typeId, setTypeId] = useState<string | null>(null)
  const [quantity, setQuantity] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [stock, setStock] = useState<{ typeId: string; available: number | null; failed: boolean } | null>(null)

  useEffect(() => {
    if (!typeId || !fromLocationId) return
    let cancelled = false
    inventoryApi
      .stock({ assetTypeId: Number(typeId), locationId: fromLocationId, perPage: 1 })
      .then(({ data }) => {
        if (!cancelled) setStock({ typeId, available: data[0]?.quantity ?? 0, failed: false })
      })
      .catch(() => {
        if (!cancelled) setStock({ typeId, available: null, failed: true })
      })
    return () => {
      cancelled = true
    }
  }, [typeId, fromLocationId])

  const items = quantityTypeItems(options, excluded)
  const current = stock && stock.typeId === typeId ? stock : null
  const typeName = options?.asset_types.find((type) => String(type.id) === typeId)?.name ?? ''

  function handleAdd() {
    setError(null)
    if (!typeId) {
      setError('Selecciona el tipo de artículo.')
      return
    }
    if (!/^\d+$/.test(quantity.trim()) || Number(quantity) < 1) {
      setError('La cantidad debe ser un número entero mayor que 0.')
      return
    }
    const value = Number(quantity)
    if (current?.available !== null && current?.available !== undefined && value > current.available) {
      setError(`Solo hay ${formatQuantity(current.available)} en ${locationName ?? 'el origen'}.`)
      return
    }
    onAdd({ key: `type-${typeId}`, type: 'quantity', assetTypeId: Number(typeId), label: typeName, quantity: value })
    setTypeId(null)
    setQuantity('')
    setStock(null)
    setResetKey((key) => key + 1)
  }

  return (
    <div className="flex flex-col gap-2">
      <h3 className="text-sm font-medium">Artículos por cantidad</h3>
      <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_120px_auto] sm:items-end">
        <CatalogSelectField
          key={resetKey}
          id="delivery-quantity-type"
          name="asset_type_id"
          label="Tipo de artículo por cantidad"
          placeholder={options ? (items.length === 0 ? 'Sin tipos por cantidad' : 'Selecciona un tipo') : 'Cargando…'}
          items={items}
          defaultValue={null}
          onValueChange={setTypeId}
        />
        <FormField id="delivery-quantity" label="Cantidad">
          <Input
            id="delivery-quantity"
            type="number"
            inputMode="numeric"
            min={1}
            step={1}
            value={quantity}
            onChange={(event) => setQuantity(event.target.value)}
          />
        </FormField>
        <Button type="button" variant="outline" onClick={handleAdd} aria-label="Agregar cantidad">
          <PlusIcon data-icon="inline-start" />
          Agregar
        </Button>
      </div>
      {typeId && (
        <p className="text-xs text-muted-foreground" aria-live="polite">
          {current === null
            ? 'Consultando existencia…'
            : current.failed
              ? 'No fue posible consultar la existencia.'
              : `Existencia en ${locationName ?? 'el origen'}: ${formatQuantity(current.available ?? 0)}`}
        </p>
      )}
      {error && <p className="text-xs text-destructive">{error}</p>}
    </div>
  )
}

// ---- Paso 6: resumen y confirmación ----

function SummaryStep({
  employee,
  kind,
  dueOn,
  locationName,
  notes,
  lines,
  signature,
}: {
  employee: EmployeeOption | null
  kind: DeliveryKind | null
  dueOn: string
  locationName: string | null
  notes: string
  lines: Line[]
  signature: string | null
}) {
  const employeeName = employee?.full_name ?? 'el empleado'
  return (
    <section aria-labelledby="summary-title" className="flex flex-col gap-4">
      <h2 id="summary-title" className="text-base font-medium">
        Resumen
      </h2>
      <dl className="grid gap-3 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-xs text-muted-foreground">Empleado</dt>
          <dd>{employee ? `${employee.full_name} · ${employee.position}` : '—'}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Tipo</dt>
          <dd>{kind ? DELIVERY_KIND_LABELS[kind] : '—'}</dd>
        </div>
        {kind === 'prestamo' && (
          <div>
            <dt className="text-xs text-muted-foreground">Devolución esperada</dt>
            <dd>{formatDate(dueOn)}</dd>
          </div>
        )}
        <div>
          <dt className="text-xs text-muted-foreground">Ubicación de origen</dt>
          <dd>{locationName ?? '—'}</dd>
        </div>
        {notes.trim() && (
          <div className="sm:col-span-2">
            <dt className="text-xs text-muted-foreground">Observaciones</dt>
            <dd className="whitespace-pre-line">{notes.trim()}</dd>
          </div>
        )}
      </dl>
      <ul className="flex flex-col gap-1 text-sm">
        {lines.map((line) => (
          <li key={line.key} className="flex justify-between gap-3 border-b py-1 last:border-b-0">
            <span>{line.label}</span>
            <span className="tabular-nums">{line.type === 'asset' ? 1 : formatQuantity(line.quantity)}</span>
          </li>
        ))}
      </ul>
      {signature && <img src={signature} alt={`Firma de ${employeeName}`} className="h-32 w-full max-w-sm rounded-lg border bg-white object-contain" />}
      <p className="text-xs text-muted-foreground">Al confirmar, los activos pasan a Asignado y las cantidades salen de la ubicación de origen. No se podrá editar ni borrar.</p>
    </section>
  )
}
