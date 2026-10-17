'use client'

import { DELIVERY_STATUS_LABELS, type DeliveryStatus } from 'app/features/entregas/schemas'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

// Piezas comunes de las pantallas de Entregas (spec 007).

export const muted = <span className="text-muted-foreground">—</span>

// Revisión de seguridad de 007 (Ley 1581 de 2012): las observaciones de
// entregas y devoluciones las ve todo el que tiene entregas.ver.
export const NOTES_PRIVACY_HINT = 'No escribas datos personales ni de salud.'

const DOT: Record<DeliveryStatus, string> = {
  open: 'bg-sky-500',
  closed: 'bg-emerald-500',
  overdue: 'bg-destructive',
}

// D-005: estado derivado (Abierta / Cerrada / Vencida). El texto lleva el significado.
export function DeliveryStatusBadge({ status, className }: { status: DeliveryStatus; className?: string }) {
  return (
    <Badge variant={status === 'overdue' ? 'destructive' : status === 'closed' ? 'secondary' : 'outline'} className={cn('gap-1.5', className)}>
      <span className={cn('size-1.5 rounded-full', DOT[status])} aria-hidden />
      {DELIVERY_STATUS_LABELS[status] ?? status}
    </Badge>
  )
}

const numberFormat = new Intl.NumberFormat('es-CO')

export function formatQuantity(value: number): string {
  return numberFormat.format(value)
}

// "Casco · B31-0001" para serializados; "Guantes de rescate" para cantidades.
export function itemLabel(item: { asset: { plate: string } | null; asset_type: { name: string } }): string {
  return item.asset ? `${item.asset_type.name} · ${item.asset.plate}` : item.asset_type.name
}
