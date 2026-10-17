'use client'

import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { getConfigurationOptions, type ConfigurationOptions } from 'app/features/configuracion/api'

// Opciones disponibles (FR-010, D-005): solo registros activos. Los selects de
// los formularios de Configuración las usan para no ofrecer padres inactivos
// (FR-007). `options` es null mientras cargan o si fallaron (`failed`).
export function useConfigurationOptions(): { options: ConfigurationOptions | null; failed: boolean } {
  const [options, setOptions] = useState<ConfigurationOptions | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let cancelled = false
    getConfigurationOptions()
      .then(({ data }) => {
        if (!cancelled) setOptions(data)
      })
      .catch(() => {
        if (cancelled) return
        setFailed(true)
        toast.error('No fue posible cargar las opciones disponibles de configuración.')
      })
    return () => {
      cancelled = true
    }
  }, [])

  return { options, failed }
}

export function optionsPlaceholder(options: unknown, failed: boolean, ready: string): string {
  if (options) return ready
  return failed ? 'No se pudieron cargar las opciones' : 'Cargando…'
}

// Opciones de un select de padre (categoría, tipo de ubicación): las
// disponibles y, al editar, también el padre actual aunque esté inactivo,
// marcado, para no obligar a cambiarlo (edge case de la spec, D-research Frontend).
export function parentSelectItems(
  available: { id: number; name: string }[],
  current: { id: number; name: string; is_active: boolean } | null | undefined,
  inactiveSuffix: string
): { value: string; label: string }[] {
  const items = available.map((option) => ({ value: String(option.id), label: option.name }))
  if (current && !available.some((option) => option.id === current.id)) {
    items.push({ value: String(current.id), label: current.is_active ? current.name : `${current.name} ${inactiveSuffix}` })
  }
  return items
}
