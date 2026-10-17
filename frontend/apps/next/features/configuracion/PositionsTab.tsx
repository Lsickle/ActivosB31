'use client'

import type { ColumnDef } from '@tanstack/react-table'
import { positionsApi, type PositionRecord } from 'app/features/configuracion/api'
import { catalogFormSchema } from 'app/features/configuracion/schemas'
import { CatalogFormDialog } from './CatalogFormDialog'
import { CatalogTab, type CatalogFetchParams, type CatalogLabels } from './CatalogTab'

const LABELS: CatalogLabels = {
  singular: 'cargo',
  plural: 'cargos',
  feminine: false,
  newLabel: 'Nuevo cargo',
  searchLabel: 'Buscar cargos',
  emptyMessage: 'No hay cargos que coincidan con los filtros.',
  // 004 US2-1: desactivar no cambia a los empleados que ya lo tienen.
  deactivateDescription: 'No se ofrecerá al registrar o editar empleados; los empleados que ya lo tienen lo conservan.',
  activateDescription: 'Volverá a ofrecerse en el formulario de empleados.',
}

const COLUMNS: ColumnDef<PositionRecord>[] = []

function fetchPositions({ search, isActive, page, perPage }: CatalogFetchParams) {
  return positionsApi.list({ search, isActive, page, perPage })
}

// specs/004-modulo-empleados US2 / FR-004 / RN-035: cargos o rangos del
// personal, administrados como los demás catálogos (sin eliminar).
export function PositionsTab() {
  return (
    <CatalogTab
      labels={LABELS}
      fetchPage={fetchPositions}
      columns={COLUMNS}
      updateStatus={positionsApi.updateStatus}
      renderForm={({ open, onOpenChange, record, onSaved }) => (
        <CatalogFormDialog
          open={open}
          onOpenChange={onOpenChange}
          idPrefix="position"
          title={record ? 'Editar cargo' : 'Nuevo cargo'}
          description="Cargo o rango del personal de la estación."
          record={record}
          schema={catalogFormSchema}
          save={(values) => (record ? positionsApi.update(record.id, values) : positionsApi.create(values))}
          onSaved={onSaved}
        />
      )}
    />
  )
}
