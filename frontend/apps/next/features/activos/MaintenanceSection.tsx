'use client'

import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { BanIcon, CircleCheckIcon, PencilIcon, PlusIcon, WrenchIcon } from 'lucide-react'
import { toast } from 'sonner'
import { assetsApi, type MaintenancePlanRecord, type MaintenanceRecordRecord } from 'app/features/activos/api'
import { DEFAULT_ALERT_DAYS, maintenancePlanSchema, maintenanceRecordSchema, type MaintenancePlanValues } from 'app/features/activos/schemas'
import { todayIsoDate } from 'app/features/empleados/schemas'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { DataTable } from '@/components/data-table'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardAction, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { TEXTAREA_CLASS } from './AssetFormDialog'
import { DueBadge } from './AssetStatusBadge'
import { dueState, formatDate, formatMoney } from './format'
import { Pager } from './Pager'

const NO_PLAN = 'none'

function planValues(plan: MaintenancePlanRecord, overrides: Partial<MaintenancePlanValues> = {}): MaintenancePlanValues {
  return {
    name: plan.name,
    interval_months: plan.interval_months,
    last_done_on: plan.last_done_on,
    alert_days: plan.alert_days,
    is_active: plan.is_active,
    ...overrides,
  }
}

function optionalLabel(text: string) {
  return (
    <>
      {text} <span className="font-normal text-muted-foreground">(opcional)</span>
    </>
  )
}

const muted = <span className="text-muted-foreground">—</span>

// US4 / RN-029 / RN-030 / D-003: planes de mantenimiento del activo (el próximo
// lo calcula el servidor) y mantenimientos realizados (inmutables, SC-003).
export function MaintenanceSection({
  assetId,
  canManage,
  onChanged,
}: {
  assetId: number
  canManage: boolean
  /** Tras crear/editar: la ficha recarga el activo (próximo mantenimiento) y el historial. */
  onChanged: () => void
}) {
  const [plans, setPlans] = useState<MaintenancePlanRecord[] | null>(null)
  const [plansError, setPlansError] = useState(false)
  const [records, setRecords] = useState<MaintenanceRecordRecord[] | null>(null)
  const [recordsMeta, setRecordsMeta] = useState<PaginationMeta | null>(null)
  const [recordsError, setRecordsError] = useState(false)
  const [recordsPage, setRecordsPage] = useState(1)
  const [reloadKey, setReloadKey] = useState(0)

  const [planForm, setPlanForm] = useState<{ open: boolean; plan: MaintenancePlanRecord | null }>({ open: false, plan: null })
  const [isRecordFormOpen, setIsRecordFormOpen] = useState(false)

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  useEffect(() => {
    let cancelled = false
    assetsApi.maintenancePlans
      .list(assetId)
      .then(({ data }) => {
        if (cancelled) return
        setPlans(data)
        setPlansError(false)
      })
      .catch(() => {
        if (!cancelled) setPlansError(true)
      })
    return () => {
      cancelled = true
    }
  }, [assetId, reloadKey])

  useEffect(() => {
    let cancelled = false
    assetsApi.maintenanceRecords
      .list(assetId, { page: recordsPage })
      .then((response) => {
        if (cancelled) return
        setRecords(response.data)
        setRecordsMeta(response.meta)
        setRecordsError(false)
      })
      .catch(() => {
        if (!cancelled) setRecordsError(true)
      })
    return () => {
      cancelled = true
    }
  }, [assetId, recordsPage, reloadKey])

  const handleToggle = useCallback(
    async (plan: MaintenancePlanRecord) => {
      const activate = !plan.is_active
      try {
        await assetsApi.maintenancePlans.update(assetId, plan.id, planValues(plan, { is_active: activate }))
        toast.success(`Plan "${plan.name}" ${activate ? 'activado' : 'desactivado'}.`)
        reload()
        onChanged()
      } catch (err) {
        toast.error(err instanceof Error ? err.message : 'No fue posible cambiar el estado del plan.')
      }
    },
    [assetId, onChanged, reload]
  )

  const planColumns = useMemo<ColumnDef<MaintenancePlanRecord>[]>(() => {
    const base: ColumnDef<MaintenancePlanRecord>[] = [
      { id: 'name', header: 'Mantenimiento', cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
      {
        id: 'interval',
        header: 'Periodicidad',
        cell: ({ row }) => `Cada ${row.original.interval_months} ${row.original.interval_months === 1 ? 'mes' : 'meses'}`,
      },
      { id: 'last', header: 'Último realizado', cell: ({ row }) => (row.original.last_done_on ? formatDate(row.original.last_done_on) : muted) },
      {
        id: 'next',
        header: 'Próximo',
        cell: ({ row }) => (
          <div className="flex flex-wrap items-center gap-1.5">
            <span>{formatDate(row.original.next_due_on)}</span>
            {row.original.is_active && <DueBadge state={dueState(row.original.next_due_on, row.original.alert_days)} />}
          </div>
        ),
      },
      { id: 'alert', header: 'Anticipación', cell: ({ row }) => `${row.original.alert_days} ${row.original.alert_days === 1 ? 'día' : 'días'}` },
      {
        id: 'is_active',
        header: 'Estado',
        cell: ({ row }) =>
          row.original.is_active ? <Badge variant="outline">Activo</Badge> : <Badge variant="secondary">Inactivo</Badge>,
      },
    ]
    if (!canManage) return base
    return [
      ...base,
      {
        id: 'actions',
        header: () => <span className="sr-only">Acciones</span>,
        cell: ({ row }) => {
          const plan = row.original
          const toggleLabel = plan.is_active ? 'Desactivar' : 'Activar'
          return (
            <div className="flex justify-end gap-1">
              <Button
                size="icon-sm"
                variant="ghost"
                aria-label={`Editar ${plan.name}`}
                title="Editar"
                onClick={() => setPlanForm({ open: true, plan })}
              >
                <PencilIcon />
              </Button>
              <Button
                size="icon-sm"
                variant="ghost"
                aria-label={`${toggleLabel} ${plan.name}`}
                title={toggleLabel}
                onClick={() => handleToggle(plan)}
              >
                {plan.is_active ? <BanIcon /> : <CircleCheckIcon />}
              </Button>
            </div>
          )
        },
      },
    ]
  }, [canManage, handleToggle])

  const recordColumns = useMemo<ColumnDef<MaintenanceRecordRecord>[]>(
    () => [
      { id: 'performed_on', header: 'Fecha', cell: ({ row }) => formatDate(row.original.performed_on) },
      { id: 'plan', header: 'Plan', cell: ({ row }) => row.original.plan?.name ?? muted },
      {
        id: 'description',
        header: 'Descripción',
        cell: ({ row }) => <span className="line-clamp-2 max-w-md whitespace-normal">{row.original.description}</span>,
      },
      { id: 'performed_by', header: 'Quién lo hizo', cell: ({ row }) => row.original.performed_by ?? muted },
      { id: 'cost', header: 'Costo', cell: ({ row }) => (row.original.cost === null ? muted : formatMoney(row.original.cost)) },
    ],
    []
  )

  function handleSaved(message: string) {
    toast.success(message)
    reload()
    onChanged()
  }

  return (
    <section aria-labelledby="asset-maintenance-title">
      <Card>
        <CardHeader>
          <CardTitle>
            <h2 id="asset-maintenance-title">Mantenimientos</h2>
          </CardTitle>
          <CardDescription>Programados por activo. El próximo se calcula desde el último realizado más la periodicidad.</CardDescription>
          {canManage && (
            <CardAction className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" onClick={() => setPlanForm({ open: true, plan: null })}>
                <PlusIcon data-icon="inline-start" />
                Nuevo plan
              </Button>
              <Button size="sm" onClick={() => setIsRecordFormOpen(true)}>
                <WrenchIcon data-icon="inline-start" />
                Registrar mantenimiento
              </Button>
            </CardAction>
          )}
        </CardHeader>
        <CardContent className="flex flex-col gap-6">
          <section aria-labelledby="asset-plans-title" className="flex flex-col gap-2">
            <h3 id="asset-plans-title" className="text-sm font-medium">
              Planes de mantenimiento
            </h3>
            {plansError ? (
              <p role="alert" className="text-sm text-destructive">
                No fue posible cargar los planes de mantenimiento.
              </p>
            ) : plans === null ? (
              <Skeleton className="h-24 w-full" />
            ) : (
              <div className="overflow-x-auto">
                <DataTable
                  columns={planColumns}
                  data={plans}
                  getRowId={(plan) => String(plan.id)}
                  emptyMessage="Este activo no tiene mantenimientos programados."
                />
              </div>
            )}
          </section>

          <section aria-labelledby="asset-records-title" className="flex flex-col gap-2">
            <h3 id="asset-records-title" className="text-sm font-medium">
              Mantenimientos realizados
            </h3>
            {recordsError ? (
              <p role="alert" className="text-sm text-destructive">
                No fue posible cargar los mantenimientos realizados.
              </p>
            ) : records === null ? (
              <Skeleton className="h-24 w-full" />
            ) : (
              <div className="overflow-x-auto">
                <DataTable
                  columns={recordColumns}
                  data={records}
                  getRowId={(record) => String(record.id)}
                  emptyMessage="Aún no se han registrado mantenimientos."
                />
              </div>
            )}
            <Pager meta={recordsMeta} singular="registro" plural="registros" onPage={setRecordsPage} />
          </section>
        </CardContent>
      </Card>

      {canManage && (
        <>
          <PlanFormDialog
            open={planForm.open}
            onOpenChange={(open) => setPlanForm((current) => ({ ...current, open }))}
            assetId={assetId}
            plan={planForm.plan}
            onSaved={(plan, created) => {
              setPlanForm({ open: false, plan: null })
              handleSaved(`Plan "${plan.name}" ${created ? 'creado' : 'actualizado'}.`)
            }}
          />
          <RecordFormDialog
            open={isRecordFormOpen}
            onOpenChange={setIsRecordFormOpen}
            assetId={assetId}
            plans={(plans ?? []).filter((plan) => plan.is_active)}
            onSaved={() => {
              setIsRecordFormOpen(false)
              handleSaved('Mantenimiento registrado.')
            }}
          />
        </>
      )}
    </section>
  )
}

// Envoltorio común: valida con `parse`, envía con `save` y muestra los 422 junto al campo.
function useFormSubmit<V>(
  parse: (formData: FormData) => { success: true; data: V } | { success: false; errors: FieldErrors },
  save: (values: V) => Promise<void>
) {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)
    const parsed = parse(new FormData(event.currentTarget))
    if (!parsed.success) {
      setErrors(parsed.errors)
      return
    }
    setErrors({})
    setIsSubmitting(true)
    try {
      await save(parsed.data)
    } catch (err) {
      if (err instanceof ApiValidationError) setErrors(apiFieldErrors(err))
      else setFormError(err instanceof Error ? err.message : 'No fue posible guardar.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return { errors, formError, isSubmitting, handleSubmit }
}

function FormFooter({ formError, isSubmitting, submitLabel, onCancel }: { formError: string | null; isSubmitting: boolean; submitLabel: string; onCancel: () => void }) {
  return (
    <>
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
          {isSubmitting ? 'Guardando…' : submitLabel}
        </Button>
      </DialogFooter>
    </>
  )
}

function PlanFormDialog({
  open,
  onOpenChange,
  assetId,
  plan,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  assetId: number
  plan: MaintenancePlanRecord | null
  onSaved: (plan: MaintenancePlanRecord, created: boolean) => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {open && <PlanForm assetId={assetId} plan={plan} onSaved={onSaved} onCancel={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  )
}

function PlanForm({
  assetId,
  plan,
  onSaved,
  onCancel,
}: {
  assetId: number
  plan: MaintenancePlanRecord | null
  onSaved: (plan: MaintenancePlanRecord, created: boolean) => void
  onCancel: () => void
}) {
  const { errors, formError, isSubmitting, handleSubmit } = useFormSubmit(
    (formData) => {
      const parsed = maintenancePlanSchema.safeParse({
        name: String(formData.get('name') ?? ''),
        interval_months: String(formData.get('interval_months') ?? ''),
        last_done_on: String(formData.get('last_done_on') ?? ''),
        alert_days: String(formData.get('alert_days') ?? ''),
        // Al editar se conserva el estado; se cambia con Activar/Desactivar.
        is_active: plan?.is_active ?? true,
      })
      return parsed.success ? { success: true, data: parsed.data } : { success: false, errors: zodFieldErrors(parsed.error) }
    },
    async (values) => {
      const { data } = plan
        ? await assetsApi.maintenancePlans.update(assetId, plan.id, values)
        : await assetsApi.maintenancePlans.create(assetId, values)
      onSaved(data, plan === null)
    }
  )

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>{plan ? 'Editar plan de mantenimiento' : 'Nuevo plan de mantenimiento'}</DialogTitle>
        <DialogDescription>P. ej. “Prueba hidrostática” cada 60 meses o “Revisión general” cada 12 meses.</DialogDescription>
      </DialogHeader>
      <FormField id="plan-name" label="Nombre" error={errors.name}>
        <Input {...fieldA11yProps('plan-name', errors.name)} name="name" defaultValue={plan?.name ?? ''} maxLength={120} required />
      </FormField>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField id="plan-interval" label="Periodicidad (meses)" error={errors.interval_months}>
          <Input
            {...fieldA11yProps('plan-interval', errors.interval_months)}
            name="interval_months"
            type="number"
            inputMode="numeric"
            min={1}
            max={600}
            step={1}
            defaultValue={plan?.interval_months ?? ''}
            required
          />
        </FormField>
        <FormField
          id="plan-last-done"
          label={optionalLabel('Último realizado')}
          error={errors.last_done_on}
          description="Sin fecha, el próximo se cuenta desde la creación del plan."
        >
          <Input
            {...fieldA11yProps('plan-last-done', errors.last_done_on, true)}
            name="last_done_on"
            type="date"
            max={todayIsoDate()}
            defaultValue={plan?.last_done_on ?? ''}
          />
        </FormField>
        <FormField
          id="plan-alert-days"
          label="Anticipación de la alerta (días)"
          error={errors.alert_days}
          description="Entre 0 y 365. Por defecto, 30."
        >
          <Input
            {...fieldA11yProps('plan-alert-days', errors.alert_days, true)}
            name="alert_days"
            type="number"
            inputMode="numeric"
            min={0}
            max={365}
            step={1}
            defaultValue={plan?.alert_days ?? DEFAULT_ALERT_DAYS}
          />
        </FormField>
      </div>
      <FormFooter formError={formError} isSubmitting={isSubmitting} submitLabel="Guardar" onCancel={onCancel} />
    </form>
  )
}

function RecordFormDialog({
  open,
  onOpenChange,
  assetId,
  plans,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  assetId: number
  /** Solo los planes activos. */
  plans: MaintenancePlanRecord[]
  onSaved: () => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {open && <RecordForm assetId={assetId} plans={plans} onSaved={onSaved} onCancel={() => onOpenChange(false)} />}
      </DialogContent>
    </Dialog>
  )
}

function RecordForm({
  assetId,
  plans,
  onSaved,
  onCancel,
}: {
  assetId: number
  plans: MaintenancePlanRecord[]
  onSaved: () => void
  onCancel: () => void
}) {
  const planItems = [{ value: NO_PLAN, label: 'Sin plan (mantenimiento puntual)' }, ...plans.map((plan) => ({ value: String(plan.id), label: plan.name }))]

  const { errors, formError, isSubmitting, handleSubmit } = useFormSubmit(
    (formData) => {
      const planValue = String(formData.get('plan_id') ?? '')
      const parsed = maintenanceRecordSchema.safeParse({
        plan_id: planValue === '' || planValue === NO_PLAN ? null : Number(planValue),
        performed_on: String(formData.get('performed_on') ?? ''),
        description: String(formData.get('description') ?? ''),
        performed_by: String(formData.get('performed_by') ?? ''),
        cost: String(formData.get('cost') ?? ''),
      })
      return parsed.success ? { success: true, data: parsed.data } : { success: false, errors: zodFieldErrors(parsed.error) }
    },
    async (values) => {
      await assetsApi.maintenanceRecords.create(assetId, values)
      onSaved()
    }
  )

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>Registrar mantenimiento</DialogTitle>
        <DialogDescription>Queda en el historial y no se puede editar ni borrar. Si es de un plan, recalcula su próximo mantenimiento.</DialogDescription>
      </DialogHeader>
      <CatalogSelectField id="record-plan" name="plan_id" label="Plan" placeholder="Sin plan" items={planItems} defaultValue={NO_PLAN} error={errors.plan_id} />
      <FormField id="record-performed-on" label="Fecha de realización" error={errors.performed_on}>
        <Input
          {...fieldA11yProps('record-performed-on', errors.performed_on)}
          name="performed_on"
          type="date"
          max={todayIsoDate()}
          defaultValue={todayIsoDate()}
          required
        />
      </FormField>
      <FormField id="record-description" label="Descripción" error={errors.description}>
        <textarea
          {...fieldA11yProps('record-description', errors.description)}
          name="description"
          maxLength={500}
          rows={3}
          required
          className={TEXTAREA_CLASS}
        />
      </FormField>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField id="record-performed-by" label={optionalLabel('Quién lo hizo')} error={errors.performed_by}>
          <Input {...fieldA11yProps('record-performed-by', errors.performed_by)} name="performed_by" maxLength={120} />
        </FormField>
        <FormField id="record-cost" label={optionalLabel('Costo (COP)')} error={errors.cost}>
          <Input {...fieldA11yProps('record-cost', errors.cost)} name="cost" type="number" inputMode="decimal" min={0} step="0.01" />
        </FormField>
      </div>
      <FormFooter formError={formError} isSubmitting={isSubmitting} submitLabel="Registrar" onCancel={onCancel} />
    </form>
  )
}
