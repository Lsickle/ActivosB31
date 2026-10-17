import { ASSET_STATUS_LABELS, type AssetStatus } from 'app/features/activos/schemas'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

const DOT: Record<AssetStatus, string> = {
  available: 'bg-emerald-500',
  assigned: 'bg-sky-500',
  maintenance: 'bg-amber-500',
  retired: 'bg-destructive',
}

// RN-005: insignia del estado con un punto de color; el texto lleva el significado.
export function AssetStatusBadge({ status, className }: { status: AssetStatus; className?: string }) {
  return (
    <Badge variant={status === 'retired' ? 'destructive' : 'outline'} className={cn('gap-1.5', className)}>
      <span className={cn('size-1.5 rounded-full', DOT[status])} aria-hidden />
      {ASSET_STATUS_LABELS[status] ?? status}
    </Badge>
  )
}

// RN-030: "Vencido" / "Próximo" para fechas de mantenimiento o vencimiento.
export function DueBadge({ state, soonLabel = 'Próximo' }: { state: 'overdue' | 'soon' | null; soonLabel?: string }) {
  if (state === 'overdue') return <Badge variant="destructive">Vencido</Badge>
  if (state === 'soon') return <Badge variant="secondary">{soonLabel}</Badge>
  return null
}
