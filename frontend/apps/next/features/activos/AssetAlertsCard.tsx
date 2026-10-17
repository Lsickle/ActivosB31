'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'
import { BellIcon } from 'lucide-react'
import { assetsApi, type AssetAlerts } from 'app/features/activos/api'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { DueBadge } from './AssetStatusBadge'
import { formatDate } from './format'

function AlertList({ title, children, empty }: { title: string; children: React.ReactNode[]; empty: string }) {
  return (
    <div className="flex min-w-0 flex-col gap-2">
      <h3 className="text-sm font-medium">{title}</h3>
      {children.length === 0 ? (
        <p className="text-xs text-muted-foreground">{empty}</p>
      ) : (
        <ul className="flex flex-col gap-2">{children}</ul>
      )}
    </div>
  )
}

function AlertItem({ assetId, plate, detail, overdue, soonLabel }: { assetId: number; plate: string; detail: string; overdue: boolean; soonLabel: string }) {
  return (
    <li className="flex items-center justify-between gap-3 rounded-lg border p-2.5 text-sm">
      <div className="min-w-0">
        <Link href={`/activos/${assetId}`} className="font-medium underline-offset-4 hover:underline">
          {plate}
        </Link>
        <p className="truncate text-xs text-muted-foreground">{detail}</p>
      </div>
      <DueBadge state={overdue ? 'overdue' : 'soon'} soonLabel={soonLabel} />
    </li>
  )
}

// US5 / D-004 (RN-006, RN-030, RN-031): mantenimientos y vencimientos próximos
// y vencidos de activos no dados de baja, con enlace a la ficha.
export function AssetAlertsCard() {
  const [alerts, setAlerts] = useState<AssetAlerts | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let cancelled = false
    assetsApi
      .alerts()
      .then(({ data }) => {
        if (!cancelled) setAlerts(data)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const isEmpty = alerts !== null && alerts.maintenance.length === 0 && alerts.expirations.length === 0

  return (
    <section aria-labelledby="asset-alerts-title">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <BellIcon className="size-4 text-muted-foreground" aria-hidden />
            <h2 id="asset-alerts-title">Alertas</h2>
          </CardTitle>
          <CardDescription>Mantenimientos y vencimientos próximos o vencidos.</CardDescription>
        </CardHeader>
        <CardContent>
          {failed ? (
            <p role="alert" className="text-sm text-destructive">
              No fue posible cargar las alertas.
            </p>
          ) : alerts === null ? (
            <Skeleton className="h-20 w-full" />
          ) : isEmpty ? (
            <p className="text-sm text-muted-foreground">No hay mantenimientos ni vencimientos próximos.</p>
          ) : (
            <div className="grid gap-4 md:grid-cols-2">
              <AlertList title="Mantenimientos" empty="Sin mantenimientos próximos.">
                {alerts.maintenance.map((alert) => (
                  <AlertItem
                    key={`plan-${alert.plan_id}`}
                    assetId={alert.asset.id}
                    plate={alert.asset.plate}
                    detail={`${alert.plan_name} · ${alert.asset.asset_type.name} · ${formatDate(alert.next_due_on)}`}
                    overdue={alert.overdue}
                    soonLabel="Próximo"
                  />
                ))}
              </AlertList>
              <AlertList title="Vencimientos" empty="Sin vencimientos próximos.">
                {alerts.expirations.map((alert) => (
                  <AlertItem
                    key={`asset-${alert.asset.id}`}
                    assetId={alert.asset.id}
                    plate={alert.asset.plate}
                    detail={`${alert.asset.asset_type.name} · vence el ${formatDate(alert.expires_on)}`}
                    overdue={alert.overdue}
                    soonLabel="Próximo a vencer"
                  />
                ))}
              </AlertList>
            </div>
          )}
        </CardContent>
      </Card>
    </section>
  )
}
