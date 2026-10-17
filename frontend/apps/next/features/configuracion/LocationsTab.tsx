'use client'

import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { locationsApi, type LocationRecord } from 'app/features/configuracion/api'
import { locationFormSchema } from 'app/features/configuracion/schemas'
import { Badge } from '@/components/ui/badge'
import { CatalogFormDialog, CatalogSelectField } from './CatalogFormDialog'
import { ALL, CatalogTab, type CatalogFetchParams, type CatalogFilter, type CatalogLabels } from './CatalogTab'
import { optionsPlaceholder, parentSelectItems, useConfigurationOptions } from './useConfigurationOptions'

const LABELS: CatalogLabels = {
  singular: 'ubicación',
  plural: 'ubicaciones',
  feminine: true,
  newLabel: 'Nueva ubicación',
  searchLabel: 'Buscar ubicaciones',
  emptyMessage: 'No hay ubicaciones que coincidan con los filtros.',
  deactivateDescription: 'No estará disponible para registros nuevos. Los registros existentes se conservan.',
  activateDescription: 'Volverá a estar disponible para registros nuevos, si su tipo de ubicación está activo.',
}

const COLUMNS: ColumnDef<LocationRecord>[] = [
  {
    id: 'location_type',
    header: 'Tipo',
    cell: ({ row }) => (
      <div className="flex flex-wrap items-center gap-1.5">
        <span>{row.original.location_type.name}</span>
        {/* FR-008: la ubicación conserva su tipo, pero no está disponible para registros nuevos. */}
        {!row.original.location_type.is_active && <Badge variant="secondary">Tipo inactivo</Badge>}
      </div>
    ),
  },
]

function fetchLocations({ search, isActive, page, perPage, filters }: CatalogFetchParams) {
  return locationsApi.list({
    search,
    isActive,
    page,
    perPage,
    locationTypeId: filters.location_type_id ? Number(filters.location_type_id) : undefined,
  })
}

function readLocationValues(formData: FormData) {
  return { location_type_id: Number(formData.get('location_type_id')) || undefined }
}

// US3 / FR-004 / RN-002: ubicaciones planas (sin ubicación padre), cada una
// con exactamente un tipo de ubicación.
export function LocationsTab() {
  const { options, failed } = useConfigurationOptions()
  const activeTypes = useMemo(() => options?.location_types ?? [], [options])

  // El filtro ofrece los tipos disponibles (activos); las ubicaciones de tipos
  // inactivos se ven con "Todos los tipos".
  const filters = useMemo<CatalogFilter[]>(
    () => [
      {
        key: 'location_type_id',
        label: 'Filtrar por tipo de ubicación',
        items: [{ value: ALL, label: 'Todos los tipos' }, ...activeTypes.map((type) => ({ value: String(type.id), label: type.name }))],
      },
    ],
    [activeTypes]
  )

  return (
    <CatalogTab
      labels={LABELS}
      fetchPage={fetchLocations}
      filters={filters}
      columns={COLUMNS}
      updateStatus={locationsApi.updateStatus}
      renderForm={({ open, onOpenChange, record, onSaved }) => (
        <CatalogFormDialog
          open={open}
          onOpenChange={onOpenChange}
          idPrefix="location"
          title={record ? 'Editar ubicación' : 'Nueva ubicación'}
          description="Lugar físico donde puede estar un activo (p. ej. Bodega principal, Máquina 1)."
          record={record}
          schema={locationFormSchema}
          // US3-1: sin tipos de ubicación activos no se puede crear una ubicación.
          blocked={!record && options !== null && activeTypes.length === 0 ? 'Primero crea un tipo de ubicación activo.' : undefined}
          readExtraValues={readLocationValues}
          extraFields={(errors) => (
            // FR-007: solo tipos activos; al editar, también el actual aunque esté inactivo.
            <CatalogSelectField
              id="location-location-type"
              name="location_type_id"
              label="Tipo de ubicación"
              placeholder={optionsPlaceholder(options, failed, 'Selecciona un tipo')}
              items={parentSelectItems(activeTypes, record?.location_type, '(inactivo)')}
              defaultValue={record ? String(record.location_type.id) : null}
              error={errors.location_type_id}
            />
          )}
          save={(values) => (record ? locationsApi.update(record.id, values) : locationsApi.create(values))}
          onSaved={onSaved}
        />
      )}
    />
  )
}
