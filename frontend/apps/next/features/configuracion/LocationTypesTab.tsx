'use client'

import type { ColumnDef } from '@tanstack/react-table'
import { locationTypesApi, type LocationTypeRecord } from 'app/features/configuracion/api'
import { locationTypeFormSchema } from 'app/features/configuracion/schemas'
import { CatalogFormDialog } from './CatalogFormDialog'
import { CatalogTab, type CatalogFetchParams, type CatalogLabels } from './CatalogTab'

const LABELS: CatalogLabels = {
  singular: 'tipo de ubicación',
  plural: 'tipos de ubicación',
  feminine: false,
  newLabel: 'Nuevo tipo de ubicación',
  searchLabel: 'Buscar tipos de ubicación',
  emptyMessage: 'No hay tipos de ubicación que coincidan con los filtros.',
  // FR-008: desactivar no se propaga a las ubicaciones.
  deactivateDescription:
    'Sus ubicaciones se conservan sin cambios, pero no estarán disponibles para registros nuevos mientras el tipo esté inactivo.',
  activateDescription: 'Volverá a estar disponible para crear ubicaciones.',
}

const COLUMNS: ColumnDef<LocationTypeRecord>[] = [{ accessorKey: 'locations_count', header: 'Ubicaciones' }]

function fetchLocationTypes({ search, isActive, page, perPage }: CatalogFetchParams) {
  return locationTypesApi.list({ search, isActive, page, perPage })
}

// US3 / FR-003 / RN-004: tipos de ubicación administrables (Bodega, Vehículo…).
export function LocationTypesTab() {
  return (
    <CatalogTab
      labels={LABELS}
      fetchPage={fetchLocationTypes}
      columns={COLUMNS}
      updateStatus={locationTypesApi.updateStatus}
      renderForm={({ open, onOpenChange, record, onSaved }) => (
        <CatalogFormDialog
          open={open}
          onOpenChange={onOpenChange}
          idPrefix="location-type"
          title={record ? 'Editar tipo de ubicación' : 'Nuevo tipo de ubicación'}
          description="Clasificación de las ubicaciones (p. ej. Bodega, Vehículo, Oficina)."
          record={record}
          schema={locationTypeFormSchema}
          save={(values) => (record ? locationTypesApi.update(record.id, values) : locationTypesApi.create(values))}
          onSaved={onSaved}
        />
      )}
    />
  )
}
