'use client'

import type { ColumnDef } from '@tanstack/react-table'
import { companiesApi, type CompanyRecord } from 'app/features/configuracion/api'
import { catalogFormSchema } from 'app/features/configuracion/schemas'
import { CatalogFormDialog } from './CatalogFormDialog'
import { CatalogTab, type CatalogFetchParams, type CatalogLabels } from './CatalogTab'

const LABELS: CatalogLabels = {
  singular: 'compañía',
  plural: 'compañías',
  feminine: true,
  newLabel: 'Nueva compañía',
  searchLabel: 'Buscar compañías',
  emptyMessage: 'No hay compañías que coincidan con los filtros.',
  // 004 US2-1: desactivar no cambia a los empleados que ya la tienen.
  deactivateDescription: 'No se ofrecerá al registrar o editar empleados; los empleados que ya la tienen la conservan.',
  activateDescription: 'Volverá a ofrecerse en el formulario de empleados.',
}

const COLUMNS: ColumnDef<CompanyRecord>[] = []

function fetchCompanies({ search, isActive, page, perPage }: CatalogFetchParams) {
  return companiesApi.list({ search, isActive, page, perPage })
}

// specs/004-modulo-empleados US2 / FR-004 / RN-036: compañías o turnos, sin
// precarga; administrados como los demás catálogos (sin eliminar).
export function CompaniesTab() {
  return (
    <CatalogTab
      labels={LABELS}
      fetchPage={fetchCompanies}
      columns={COLUMNS}
      updateStatus={companiesApi.updateStatus}
      renderForm={({ open, onOpenChange, record, onSaved }) => (
        <CatalogFormDialog
          open={open}
          onOpenChange={onOpenChange}
          idPrefix="company"
          title={record ? 'Editar compañía' : 'Nueva compañía'}
          description="Compañía o turno al que pertenece el personal."
          record={record}
          schema={catalogFormSchema}
          save={(values) => (record ? companiesApi.update(record.id, values) : companiesApi.create(values))}
          onSaved={onSaved}
        />
      )}
    />
  )
}
