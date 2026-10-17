'use client'

import { useCallback, useEffect, useState } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { PlusIcon } from 'lucide-react'
import { inventoryApi, type InventoryCount, type InventoryCountSummary } from 'app/features/inventario/api'
import { COUNT_STATUS_LABELS, newCountSchema } from 'app/features/inventario/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { useCan } from 'app/provider/auth'
import { DataTable } from '@/components/data-table'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { TEXTAREA_CLASS } from '@/features/activos/AssetFormDialog'
import { formatDateTime } from '@/features/activos/format'
import { Pager } from '@/features/activos/Pager'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { optionsPlaceholder } from '@/features/configuracion/useConfigurationOptions'
import { CountDetail } from './CountDetail'
import { PER_PAGE, activeLocationItems, muted, type OptionsProps } from './shared'

function columns(onOpen: (count: InventoryCountSummary) => void, canManage: boolean): ColumnDef<InventoryCountSummary>[] {
  return [
    {
      id: 'id',
      header: 'Conteo',
      cell: ({ row }) => <span className="tabular-nums">#{row.original.id}</span>,
    },
    {
      id: 'location',
      header: 'Ubicación',
      cell: ({ row }) => <span className="font-medium">{row.original.location.name}</span>,
    },
    {
      id: 'status',
      header: 'Estado',
      cell: ({ row }) => (
        <Badge variant={row.original.status === 'open' ? 'default' : 'outline'}>{COUNT_STATUS_LABELS[row.original.status]}</Badge>
      ),
    },
    {
      id: 'opened',
      header: 'Abierto',
      cell: ({ row }) => (
        <div className="flex flex-col">
          <span className="whitespace-nowrap tabular-nums">{formatDateTime(row.original.opened_at)}</span>
          {row.original.opened_by && <span className="text-xs text-muted-foreground">por {row.original.opened_by.name}</span>}
        </div>
      ),
    },
    {
      id: 'closed',
      header: 'Cerrado',
      cell: ({ row }) =>
        row.original.closed_at ? (
          <div className="flex flex-col">
            <span className="whitespace-nowrap tabular-nums">{formatDateTime(row.original.closed_at)}</span>
            {row.original.closed_by && <span className="text-xs text-muted-foreground">por {row.original.closed_by.name}</span>}
          </div>
        ) : (
          muted
        ),
    },
    {
      id: 'actions',
      header: () => <span className="sr-only">Acciones</span>,
      cell: ({ row }) => {
        const resume = row.original.status === 'open' && canManage
        return (
          <Button
            size="sm"
            variant="outline"
            aria-label={`${resume ? 'Continuar conteo' : 'Ver conteo'} #${row.original.id}`}
            onClick={() => onOpen(row.original)}
          >
            {resume ? 'Continuar' : 'Ver'}
          </Button>
        )
      },
    },
  ]
}

// US3 / RN-027: conteos físicos por ubicación. Listado; al abrir uno se
// muestra su detalle en la misma pestaña.
export function CountsTab({ options, optionsFailed }: OptionsProps) {
  const canManage = useCan('inventario.gestionar')
  const [selectedId, setSelectedId] = useState<number | null>(null)

  if (selectedId !== null) {
    return <CountDetail countId={selectedId} canManage={canManage} options={options} onBack={() => setSelectedId(null)} />
  }

  return <CountsList canManage={canManage} options={options} optionsFailed={optionsFailed} onOpen={setSelectedId} />
}

function CountsList({ canManage, options, optionsFailed, onOpen }: OptionsProps & { canManage: boolean; onOpen: (id: number) => void }) {
  const [records, setRecords] = useState<InventoryCountSummary[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [page, setPage] = useState(1)
  const [isFormOpen, setIsFormOpen] = useState(false)

  useEffect(() => {
    let cancelled = false
    inventoryApi.counts
      .list({ page, perPage: PER_PAGE })
      .then((response) => {
        if (cancelled) return
        setRecords(response.data)
        setMeta(response.meta)
        setLoadError(false)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [page])

  const openRecord = useCallback((count: InventoryCountSummary) => onOpen(count.id), [onOpen])

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <p className="text-sm text-muted-foreground">
          Al cerrar un conteo, las diferencias de cantidad se registran como ajustes; los activos no encontrados solo se reportan.
        </p>
        {canManage && (
          <Button className="sm:ml-auto" onClick={() => setIsFormOpen(true)}>
            <PlusIcon data-icon="inline-start" />
            Nuevo conteo
          </Button>
        )}
      </div>

      {loadError ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar los conteos.
        </p>
      ) : records === null ? (
        <Skeleton className="h-48 w-full" />
      ) : (
        <div className="overflow-x-auto">
          <DataTable
            columns={columns(openRecord, canManage)}
            data={records}
            getRowId={(record) => String(record.id)}
            emptyMessage="Todavía no hay conteos."
          />
        </div>
      )}

      <Pager meta={meta} singular="conteo" plural="conteos" onPage={setPage} />

      {canManage && (
        <NewCountDialog
          open={isFormOpen}
          onOpenChange={setIsFormOpen}
          options={options}
          optionsFailed={optionsFailed}
          onCreated={(count) => {
            setIsFormOpen(false)
            onOpen(count.id)
          }}
        />
      )}
    </div>
  )
}

// D-004: abrir un conteo de una ubicación (activa); uno abierto por ubicación.
function NewCountDialog({
  open,
  onOpenChange,
  options,
  optionsFailed,
  onCreated,
}: OptionsProps & {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreated: (count: InventoryCount) => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        {/* Se monta de nuevo en cada apertura: arranca sin errores previos. */}
        {open && (
          <NewCountForm options={options} optionsFailed={optionsFailed} onCancel={() => onOpenChange(false)} onCreated={onCreated} />
        )}
      </DialogContent>
    </Dialog>
  )
}

function NewCountForm({
  options,
  optionsFailed,
  onCancel,
  onCreated,
}: OptionsProps & {
  onCancel: () => void
  onCreated: (count: InventoryCount) => void
}) {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)
    const formData = new FormData(event.currentTarget)
    const location = String(formData.get('location_id') ?? '')
    const parsed = newCountSchema.safeParse({
      location_id: location === '' ? undefined : Number(location),
      notes: String(formData.get('notes') ?? ''),
    })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      const { data } = await inventoryApi.counts.create(parsed.data)
      onCreated(data)
    } catch (err) {
      if (err instanceof ApiValidationError) setErrors(apiFieldErrors(err))
      else setFormError(err instanceof Error ? err.message : 'No fue posible abrir el conteo.')
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
      <DialogHeader>
        <DialogTitle>Nuevo conteo</DialogTitle>
        <DialogDescription>
          Se listará lo que debería haber en la ubicación: cantidades por tipo y activos serializados disponibles o en mantenimiento.
        </DialogDescription>
      </DialogHeader>
      <CatalogSelectField
        id="count-location"
        name="location_id"
        label="Ubicación"
        placeholder={optionsPlaceholder(options, optionsFailed, 'Selecciona la ubicación')}
        items={activeLocationItems(options)}
        defaultValue={null}
        error={errors.location_id}
      />
      <FormField
        id="count-notes"
        label={
          <>
            Notas <span className="font-normal text-muted-foreground">(opcional)</span>
          </>
        }
        error={errors.notes}
      >
        <textarea {...fieldA11yProps('count-notes', errors.notes)} name="notes" maxLength={500} rows={2} className={TEXTAREA_CLASS} />
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
          {isSubmitting ? 'Abriendo…' : 'Abrir conteo'}
        </Button>
      </DialogFooter>
    </form>
  )
}
