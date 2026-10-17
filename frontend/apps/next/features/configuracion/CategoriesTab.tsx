'use client'

import type { ColumnDef } from '@tanstack/react-table'
import { assetCategoriesApi, type AssetCategoryRecord } from 'app/features/configuracion/api'
import { assetCategoryFormSchema } from 'app/features/configuracion/schemas'
import { CatalogFormDialog } from './CatalogFormDialog'
import { CatalogTab, type CatalogFetchParams, type CatalogLabels } from './CatalogTab'

const LABELS: CatalogLabels = {
  singular: 'categoría',
  plural: 'categorías',
  feminine: true,
  newLabel: 'Nueva categoría',
  searchLabel: 'Buscar categorías',
  emptyMessage: 'No hay categorías que coincidan con los filtros.',
  // FR-008: desactivar no se propaga a los tipos.
  deactivateDescription:
    'Sus tipos de activo se conservan sin cambios, pero no estarán disponibles para registros nuevos mientras la categoría esté inactiva.',
  activateDescription: 'Volverá a estar disponible para crear tipos de activo y registrar activos.',
}

const COLUMNS: ColumnDef<AssetCategoryRecord>[] = [
  { accessorKey: 'asset_types_count', header: 'Tipos de activo' },
]

function fetchCategories({ search, isActive, page, perPage }: CatalogFetchParams) {
  return assetCategoriesApi.list({ search, isActive, page, perPage })
}

// US1 / FR-001: categorías de activo (raíz de la clasificación).
export function CategoriesTab() {
  return (
    <CatalogTab
      labels={LABELS}
      fetchPage={fetchCategories}
      columns={COLUMNS}
      updateStatus={assetCategoriesApi.updateStatus}
      renderForm={({ open, onOpenChange, record, onSaved }) => (
        <CatalogFormDialog
          open={open}
          onOpenChange={onOpenChange}
          idPrefix="category"
          title={record ? 'Editar categoría' : 'Nueva categoría'}
          description="Familia en que la estación agrupa sus bienes (p. ej. EPP, Comunicaciones)."
          record={record}
          schema={assetCategoryFormSchema}
          save={(values) => (record ? assetCategoriesApi.update(record.id, values) : assetCategoriesApi.create(values))}
          onSaved={onSaved}
        />
      )}
    />
  )
}
