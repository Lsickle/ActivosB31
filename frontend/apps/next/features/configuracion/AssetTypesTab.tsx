'use client'

import { useMemo } from 'react'
import type { ColumnDef } from '@tanstack/react-table'
import { assetTypesApi, type AssetTypeRecord } from 'app/features/configuracion/api'
import { TRACKING_MODE_LABELS, TRACKING_MODES, assetTypeFormSchema, type TrackingMode } from 'app/features/configuracion/schemas'
import { Badge } from '@/components/ui/badge'
import { CatalogFormDialog, CatalogSelectField } from './CatalogFormDialog'
import { ALL, CatalogTab, type CatalogFetchParams, type CatalogFilter, type CatalogLabels } from './CatalogTab'
import { optionsPlaceholder, parentSelectItems, useConfigurationOptions } from './useConfigurationOptions'

const LABELS: CatalogLabels = {
  singular: 'tipo de activo',
  plural: 'tipos de activo',
  feminine: false,
  newLabel: 'Nuevo tipo de activo',
  searchLabel: 'Buscar tipos de activo',
  emptyMessage: 'No hay tipos de activo que coincidan con los filtros.',
  deactivateDescription: 'No estará disponible para registrar activos nuevos. Los registros existentes se conservan.',
  activateDescription: 'Volverá a estar disponible para registrar activos, si su categoría está activa.',
}

const TRACKING_MODE_ITEMS = TRACKING_MODES.map((mode) => ({ value: mode, label: TRACKING_MODE_LABELS[mode] }))

const MODE_FILTER: CatalogFilter = {
  key: 'tracking_mode',
  label: 'Filtrar por modo de control',
  items: [{ value: ALL, label: 'Todos los modos' }, ...TRACKING_MODE_ITEMS],
}

const COLUMNS: ColumnDef<AssetTypeRecord>[] = [
  {
    id: 'category',
    header: 'Categoría',
    cell: ({ row }) => (
      <div className="flex flex-wrap items-center gap-1.5">
        <span>{row.original.category.name}</span>
        {/* US2-4: el tipo conserva su estado, pero no está disponible para registros nuevos. */}
        {!row.original.category.is_active && <Badge variant="secondary">Categoría inactiva</Badge>}
      </div>
    ),
  },
  {
    accessorKey: 'tracking_mode',
    header: 'Modo',
    cell: ({ row }) => TRACKING_MODE_LABELS[row.original.tracking_mode] ?? row.original.tracking_mode_label,
  },
]

function fetchAssetTypes({ search, isActive, page, perPage, filters }: CatalogFetchParams) {
  return assetTypesApi.list({
    search,
    isActive,
    page,
    perPage,
    assetCategoryId: filters.asset_category_id ? Number(filters.asset_category_id) : undefined,
    trackingMode: filters.tracking_mode as TrackingMode | undefined,
  })
}

function readAssetTypeValues(formData: FormData) {
  return {
    asset_category_id: Number(formData.get('asset_category_id')) || undefined,
    tracking_mode: formData.get('tracking_mode') || undefined,
  }
}

// US2 / FR-002: tipos de activo, cada uno en una categoría (1:N) y con modo de
// control serializado o por cantidad (RN-007).
export function AssetTypesTab() {
  const { options, failed } = useConfigurationOptions()
  const activeCategories = useMemo(() => options?.asset_categories ?? [], [options])

  // El filtro ofrece las categorías disponibles (activas); los tipos de
  // categorías inactivas se ven con "Todas las categorías".
  const filters = useMemo<CatalogFilter[]>(
    () => [
      {
        key: 'asset_category_id',
        label: 'Filtrar por categoría',
        items: [
          { value: ALL, label: 'Todas las categorías' },
          ...activeCategories.map((category) => ({ value: String(category.id), label: category.name })),
        ],
      },
      MODE_FILTER,
    ],
    [activeCategories]
  )

  return (
    <CatalogTab
      labels={LABELS}
      fetchPage={fetchAssetTypes}
      filters={filters}
      columns={COLUMNS}
      updateStatus={assetTypesApi.updateStatus}
      renderForm={({ open, onOpenChange, record, onSaved }) => (
        <CatalogFormDialog
          open={open}
          onOpenChange={onOpenChange}
          idPrefix="asset-type"
          title={record ? 'Editar tipo de activo' : 'Nuevo tipo de activo'}
          description="Clase concreta de bien dentro de una categoría (p. ej. Cilindro SCBA)."
          record={record}
          schema={assetTypeFormSchema}
          blocked={!record && options !== null && activeCategories.length === 0 ? 'Primero crea una categoría activa.' : undefined}
          readExtraValues={readAssetTypeValues}
          extraFields={(errors) => (
            <>
              {/* FR-007: solo categorías activas; al editar, también la actual aunque esté inactiva. */}
              <CatalogSelectField
                id="asset-type-category"
                name="asset_category_id"
                label="Categoría"
                placeholder={optionsPlaceholder(options, failed, 'Selecciona una categoría')}
                items={parentSelectItems(activeCategories, record?.category, '(inactiva)')}
                defaultValue={record ? String(record.category.id) : null}
                error={errors.asset_category_id}
              />
              {/* RN-007 / FR-009: el servidor rechaza el cambio si el tipo ya tiene activos. */}
              <CatalogSelectField
                id="asset-type-tracking-mode"
                name="tracking_mode"
                label="Modo de control"
                placeholder="Selecciona el modo"
                items={TRACKING_MODE_ITEMS}
                defaultValue={record?.tracking_mode ?? null}
                error={errors.tracking_mode}
                description="Serializado: cada unidad se registra por separado. Por cantidad: se controlan existencias."
              />
            </>
          )}
          save={(values) => (record ? assetTypesApi.update(record.id, values) : assetTypesApi.create(values))}
          onSaved={onSaved}
        />
      )}
    />
  )
}
