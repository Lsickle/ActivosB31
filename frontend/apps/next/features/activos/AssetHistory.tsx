'use client'

import { useEffect, useState } from 'react'
import { assetsApi, type AssetEventRecord } from 'app/features/activos/api'
import { ASSET_STATUS_LABELS, type AssetStatus } from 'app/features/activos/schemas'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { formatDateTime } from './format'
import { Pager } from './Pager'

// Nombres legibles de los campos que puede traer `changes` de un evento `updated`.
const FIELD_LABELS: Record<string, string> = {
  asset_type_id: 'Tipo',
  plate: 'Placa',
  serial: 'Serial',
  brand: 'Marca',
  model: 'Modelo',
  acquisition_date: 'Fecha de adquisición',
  acquisition_value: 'Valor de adquisición',
  useful_life_months: 'Vida útil (meses)',
  expires_on: 'Vencimiento',
  notes: 'Observaciones',
  photo: 'Foto',
  // Planes de mantenimiento (A-1 de la revisión de seguridad de 005).
  name: 'Nombre',
  interval_months: 'Periodicidad (meses)',
  last_done_on: 'Último realizado',
  alert_days: 'Anticipación (días)',
  is_active: 'Activo',
}

function statusLabel(status: AssetStatus | null): string {
  return status ? (ASSET_STATUS_LABELS[status] ?? status) : '—'
}

function show(value: unknown): string {
  return value === null || value === undefined || value === '' ? '(vacío)' : String(value)
}

// D-002: cada evento en una frase en español (título + detalle).
export function describeEvent(event: AssetEventRecord): { title: string; details: string[] } {
  const notes = event.notes ? [event.notes] : []
  switch (event.event) {
    case 'created':
      return { title: 'Activo registrado', details: notes }
    case 'updated': {
      const changes = Object.entries(event.changes ?? {}).map(
        ([field, change]) => `${FIELD_LABELS[field] ?? field}: ${show(change.from)} → ${show(change.to)}`
      )
      return { title: 'Datos editados', details: changes.length > 0 ? changes : notes }
    }
    case 'status_changed':
      return { title: 'Cambio de estado', details: [`${statusLabel(event.from_status)} → ${statusLabel(event.to_status)}`, ...notes] }
    case 'location_changed':
      return {
        title: 'Cambio de ubicación',
        details: [`${event.from_location?.name ?? 'Sin ubicación'} → ${event.to_location?.name ?? 'Sin ubicación'}`, ...notes],
      }
    case 'delivered':
      return { title: 'Entregado', details: [`Entregado a ${event.employee?.full_name ?? 'un empleado'}`, ...notes] }
    case 'returned':
      return { title: 'Devuelto', details: [`Devuelto por ${event.employee?.full_name ?? 'un empleado'}`, ...notes] }
    case 'maintenance_done':
      return { title: 'Mantenimiento realizado', details: notes }
    case 'maintenance_plan_created':
      return { title: 'Plan de mantenimiento creado', details: notes }
    case 'maintenance_plan_updated': {
      const changes = Object.entries(event.changes ?? {}).map(
        ([field, change]) => `${FIELD_LABELS[field] ?? field}: ${show(change.from)} → ${show(change.to)}`
      )
      return { title: 'Plan de mantenimiento modificado', details: [...notes, ...changes] }
    }
    case 'retired':
      return { title: 'Dado de baja', details: event.notes ? [`Motivo: ${event.notes}`] : [] }
    default:
      return { title: String(event.event), details: notes }
  }
}

// US3 / FR-004: historial inmutable del activo, del más reciente al más antiguo.
export function AssetHistory({ assetId, reloadKey }: { assetId: number; reloadKey: number }) {
  const [events, setEvents] = useState<AssetEventRecord[] | null>(null)
  const [meta, setMeta] = useState<PaginationMeta | null>(null)
  const [failed, setFailed] = useState(false)
  const [page, setPage] = useState(1)

  useEffect(() => {
    let cancelled = false
    assetsApi
      .listEvents(assetId, { page })
      .then((response) => {
        if (cancelled) return
        setEvents(response.data)
        setMeta(response.meta)
        setFailed(false)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [assetId, page, reloadKey])

  return (
    <section aria-labelledby="asset-history-title">
      <Card>
        <CardHeader>
          <CardTitle>
            <h2 id="asset-history-title">Historial</h2>
          </CardTitle>
          <CardDescription>Todo lo ocurrido con el activo. No se edita ni se borra.</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {failed ? (
            <p role="alert" className="text-sm text-destructive">
              No fue posible cargar el historial.
            </p>
          ) : events === null ? (
            <Skeleton className="h-24 w-full" />
          ) : events.length === 0 ? (
            <p className="text-sm text-muted-foreground">Sin eventos.</p>
          ) : (
            <ol className="flex flex-col gap-3 border-l pl-4">
              {events.map((event) => {
                const { title, details } = describeEvent(event)
                return (
                  <li key={event.id} className="relative">
                    <span className="absolute top-1.5 -left-[1.3rem] size-2 rounded-full bg-muted-foreground" aria-hidden />
                    <p className="text-sm font-medium">{title}</p>
                    {details.map((detail) => (
                      <p key={detail} className="text-sm whitespace-pre-line text-muted-foreground">
                        {detail}
                      </p>
                    ))}
                    <p className="text-xs text-muted-foreground">
                      <time dateTime={event.created_at}>{formatDateTime(event.created_at)}</time>
                      {event.user ? ` · ${event.user.name}` : ''}
                    </p>
                  </li>
                )
              })}
            </ol>
          )}
          <Pager meta={meta} singular="evento" plural="eventos" onPage={setPage} />
        </CardContent>
      </Card>
    </section>
  )
}
