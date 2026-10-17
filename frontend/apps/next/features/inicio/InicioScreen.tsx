'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'
import { BellIcon, BoxesIcon, CheckCircle2Icon, HandshakeIcon, PackageIcon } from 'lucide-react'
import { ASSET_STATUSES, ASSET_STATUS_LABELS } from 'app/features/activos/schemas'
import { dashboardApi, type Dashboard } from 'app/features/reportes/api'
import { useRequireAuth } from 'app/provider/auth'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { DueBadge } from '@/features/activos/AssetStatusBadge'
import { formatDate } from '@/features/activos/format'
import { formatNumber } from '@/features/reportes/shared'

// Tablero de Inicio (spec 008 US4, D-004). No exige permiso de módulo: el
// servidor solo envía las secciones de los módulos que el usuario puede ver.
export function InicioScreen() {
  const { isAuthorized, user } = useRequireAuth()

  if (!isAuthorized) {
    return null
  }

  return <InicioDashboard userName={user?.name ?? null} />
}

function InicioDashboard({ userName }: { userName: string | null }) {
  const [dashboard, setDashboard] = useState<Dashboard | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let cancelled = false
    dashboardApi
      .get()
      .then(({ data }) => {
        if (!cancelled) setDashboard(data)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-xl font-semibold">{userName ? `Hola, ${userName}` : 'Inicio'}</h1>
        <p className="text-sm text-muted-foreground">Resumen del inventario y alertas de la estación.</p>
      </div>
      {failed ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar el tablero.
        </p>
      ) : dashboard === null ? (
        <div className="grid gap-4 md:grid-cols-2">
          <Skeleton className="h-40 w-full" />
          <Skeleton className="h-40 w-full" />
        </div>
      ) : (
        <DashboardSections dashboard={dashboard} />
      )}
    </div>
  )
}

function hasAlerts(dashboard: Dashboard): boolean {
  return Boolean(
    (dashboard.assets && (dashboard.assets.alerts.maintenance.length > 0 || dashboard.assets.alerts.expirations.length > 0)) ||
      (dashboard.inventory && dashboard.inventory.below_min.length > 0) ||
      (dashboard.deliveries && dashboard.deliveries.overdue.length > 0)
  )
}

function DashboardSections({ dashboard }: { dashboard: Dashboard }) {
  const { assets, inventory, deliveries } = dashboard

  if (!assets && !inventory && !deliveries) {
    return <p className="text-sm text-muted-foreground">No tienes módulos con indicadores para mostrar en el tablero.</p>
  }

  return (
    <div className="flex flex-col gap-4">
      {!hasAlerts(dashboard) && (
        <p className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm">
          <CheckCircle2Icon className="size-4 text-emerald-600 dark:text-emerald-400" aria-hidden />
          Todo en orden: no hay alertas pendientes.
        </p>
      )}
      <div className="grid gap-4 xl:grid-cols-2">
        {assets && (
          <Section id="home-assets" title="Activos" icon={<PackageIcon />} description={`${formatNumber(assets.total)} activos registrados.`}>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Stat label="Total" value={assets.total} />
              {ASSET_STATUSES.filter((status) => status !== 'retired').map((status) => (
                <Stat key={status} label={ASSET_STATUS_LABELS[status]} value={assets.by_status[status]} />
              ))}
              <Stat label={ASSET_STATUS_LABELS.retired} value={assets.by_status.retired} />
            </dl>
          </Section>
        )}
        {deliveries && (
          <Section id="home-deliveries" title="Entregas" icon={<HandshakeIcon />} description="Entregas con artículos pendientes y préstamos vencidos.">
            <dl className="grid grid-cols-2 gap-3">
              <Stat label="Entregas abiertas" value={deliveries.open_count} />
              <Stat label="Préstamos vencidos" value={deliveries.overdue_count} tone={deliveries.overdue_count > 0 ? 'alert' : undefined} />
            </dl>
            {deliveries.overdue.length === 0 ? (
              <p className="text-sm text-muted-foreground">No hay préstamos vencidos.</p>
            ) : (
              <ul className="flex flex-col gap-2" aria-label="Préstamos vencidos">
                {deliveries.overdue.map((alert) => (
                  <AlertRow
                    key={alert.id}
                    href={`/entregas/${alert.id}`}
                    title={alert.code}
                    detail={`${alert.employee.full_name} · debía devolver el ${formatDate(alert.due_on)} · ${formatNumber(alert.pending_quantity)} pendiente${alert.pending_quantity === 1 ? '' : 's'}`}
                    badge={<DueBadge state="overdue" />}
                  />
                ))}
              </ul>
            )}
          </Section>
        )}
        {assets && (
          <Section id="home-asset-alerts" title="Alertas de activos" icon={<BellIcon />} description="Mantenimientos y vencimientos próximos o vencidos.">
            {assets.alerts.maintenance.length === 0 && assets.alerts.expirations.length === 0 ? (
              <p className="text-sm text-muted-foreground">No hay mantenimientos ni vencimientos próximos.</p>
            ) : (
              <ul className="flex flex-col gap-2" aria-label="Mantenimientos y vencimientos">
                {assets.alerts.maintenance.map((alert) => (
                  <AlertRow
                    key={`plan-${alert.plan_id}`}
                    href={`/activos/${alert.asset.id}`}
                    title={alert.asset.plate}
                    detail={`Mantenimiento: ${alert.plan_name} · ${alert.asset.asset_type.name} · ${formatDate(alert.next_due_on)}`}
                    badge={<DueBadge state={alert.overdue ? 'overdue' : 'soon'} soonLabel="Próximo" />}
                  />
                ))}
                {assets.alerts.expirations.map((alert) => (
                  <AlertRow
                    key={`expiry-${alert.asset.id}`}
                    href={`/activos/${alert.asset.id}`}
                    title={alert.asset.plate}
                    detail={`Vencimiento: ${alert.asset.asset_type.name} · ${formatDate(alert.expires_on)}`}
                    badge={<DueBadge state={alert.overdue ? 'overdue' : 'soon'} soonLabel="Próximo a vencer" />}
                  />
                ))}
              </ul>
            )}
          </Section>
        )}
        {inventory && (
          <Section id="home-inventory" title="Inventario bajo mínimo" icon={<BoxesIcon />} description="Tipos por cantidad cuyo total está por debajo de su stock mínimo.">
            {inventory.below_min.length === 0 ? (
              <p className="text-sm text-muted-foreground">Ningún artículo está por debajo de su stock mínimo.</p>
            ) : (
              <ul className="flex flex-col gap-2" aria-label="Tipos bajo mínimo">
                {inventory.below_min.map((alert) => (
                  <AlertRow
                    key={alert.asset_type.id}
                    href="/inventario"
                    title={alert.asset_type.name}
                    detail={alert.asset_type.category.name}
                    badge={
                      <span className="text-xs whitespace-nowrap text-destructive tabular-nums">
                        {`${formatNumber(alert.type_total)} de ${formatNumber(alert.min_stock)} mínimo`}
                      </span>
                    }
                  />
                ))}
              </ul>
            )}
          </Section>
        )}
      </div>
    </div>
  )
}

function Section({
  id,
  title,
  icon,
  description,
  children,
}: {
  id: string
  title: string
  icon: React.ReactNode
  description: string
  children: React.ReactNode
}) {
  return (
    <section aria-labelledby={`${id}-title`}>
      <Card className="h-full">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 [&_svg]:size-4 [&_svg]:text-muted-foreground">
            <span aria-hidden>{icon}</span>
            <h2 id={`${id}-title`}>{title}</h2>
          </CardTitle>
          <CardDescription>{description}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">{children}</CardContent>
      </Card>
    </section>
  )
}

function Stat({ label, value, tone }: { label: string; value: number; tone?: 'alert' }) {
  return (
    <div className="rounded-lg border p-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={tone === 'alert' ? 'text-2xl font-semibold text-destructive tabular-nums' : 'text-2xl font-semibold tabular-nums'}>
        {formatNumber(value)}
      </dd>
    </div>
  )
}

function AlertRow({ href, title, detail, badge }: { href: string; title: string; detail: string; badge: React.ReactNode }) {
  return (
    <li className="flex items-center justify-between gap-3 rounded-lg border p-2.5 text-sm">
      <div className="min-w-0">
        <Link href={href} className="font-medium underline-offset-4 hover:underline">
          {title}
        </Link>
        <p className="truncate text-xs text-muted-foreground">{detail}</p>
      </div>
      {badge}
    </li>
  )
}
