'use client'

import type { ConfigurationOptions } from 'app/features/configuracion/api'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

// Piezas comunes de las pestañas de Inventario (spec 006).

export const ALL = 'all'
export const PER_PAGE = 25

export type FilterItem = { value: string; label: string }

export type OptionsProps = {
  /** null mientras cargan o si fallaron (`optionsFailed`). */
  options: ConfigurationOptions | null
  optionsFailed: boolean
}

export function FilterSelect({
  label,
  items,
  value,
  onChange,
}: {
  label: string
  items: FilterItem[]
  value: string
  onChange: (value: string) => void
}) {
  return (
    <Select items={items} value={value} onValueChange={(next) => onChange((next as string | null) ?? ALL)}>
      <SelectTrigger aria-label={label} className="sm:w-48">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {items.map((item) => (
          <SelectItem key={item.value} value={item.value}>
            {item.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

export function idFilter(value: string): number | undefined {
  return value === ALL ? undefined : Number(value)
}

// RN-007: Inventario solo maneja tipos por cantidad (activos y de categoría
// activa: así llegan en las opciones). `categoryId` restringe a una categoría.
export function quantityTypes(options: ConfigurationOptions | null, categoryId?: string) {
  return (options?.asset_types ?? [])
    .filter((type) => type.tracking_mode === 'quantity')
    .filter((type) => !categoryId || categoryId === ALL || String(type.asset_category_id) === categoryId)
}

// "Tipo (Categoría)" para los selects de formulario.
export function quantityTypeItems(options: ConfigurationOptions | null, exclude: Set<number> = new Set()): FilterItem[] {
  const categories = new Map((options?.asset_categories ?? []).map((category) => [category.id, category.name]))
  return quantityTypes(options)
    .filter((type) => !exclude.has(type.id))
    .map((type) => {
      const category = categories.get(type.asset_category_id)
      return { value: String(type.id), label: category ? `${type.name} (${category})` : type.name }
    })
}

export function activeLocationItems(options: ConfigurationOptions | null): FilterItem[] {
  return (options?.locations ?? []).map((location) => ({ value: String(location.id), label: location.name }))
}

const numberFormat = new Intl.NumberFormat('es-CO')

export function formatQuantity(value: number): string {
  return numberFormat.format(value)
}

// Diferencia contado − esperado con signo menos tipográfico ("−2", "+3", "0").
export function formatDifference(value: number): string {
  if (value > 0) return `+${formatQuantity(value)}`
  if (value < 0) return `−${formatQuantity(-value)}`
  return '0'
}

export const muted = <span className="text-muted-foreground">—</span>
