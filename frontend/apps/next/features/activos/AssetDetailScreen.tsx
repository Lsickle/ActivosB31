'use client'
/* eslint-disable @next/next/no-img-element -- la foto es una URL temporal firmada (FR-008): next/image no aplica. */

import { useCallback, useEffect, useState } from 'react'
import Link from 'next/link'
import { useParams } from 'next/navigation'
import { ArrowLeftIcon, ImageIcon, PencilIcon } from 'lucide-react'
import { toast } from 'sonner'
import { assetsApi, type AssetRecord } from 'app/features/activos/api'
import { ApiError } from 'app/lib/api-client'
import { useCan, useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useConfigurationOptions } from '@/features/configuracion/useConfigurationOptions'
import { AssetFormDialog } from './AssetFormDialog'
import { AssetHistory } from './AssetHistory'
import { AssetStatusActions } from './AssetStatusActions'
import { AssetStatusBadge, DueBadge } from './AssetStatusBadge'
import { dueState, formatDate, formatMoney } from './format'
import { MaintenanceSection } from './MaintenanceSection'

// Ficha del activo (/activos/[id], spec 005 US1-6, US2, US3, US4):
// ver = activos.ver; acciones = activos.gestionar (FR-007).
export function AssetDetailScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('activos.ver')
  const params = useParams<{ id: string }>()
  const assetId = Number(params?.id)

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return <AssetDetail assetId={assetId} />
}

function BackLink({ children = 'Activos' }: { children?: React.ReactNode }) {
  return (
    <Link href="/activos" className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
      <ArrowLeftIcon className="size-4" aria-hidden />
      {children}
    </Link>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex flex-col gap-0.5">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  )
}

const empty = <span className="text-muted-foreground">—</span>

function AssetDetail({ assetId }: { assetId: number }) {
  const canManage = useCan('activos.gestionar')
  const { options, failed: optionsFailed } = useConfigurationOptions()
  const [asset, setAsset] = useState<AssetRecord | null>(null)
  const [loadError, setLoadError] = useState<'not_found' | 'error' | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [isFormOpen, setIsFormOpen] = useState(false)

  // Recarga el activo y el historial tras cualquier acción.
  const reload = useCallback(() => setReloadKey((key) => key + 1), [])

  const isValidId = Number.isInteger(assetId) && assetId > 0

  useEffect(() => {
    if (!isValidId) return
    let cancelled = false
    assetsApi
      .get(assetId)
      .then(({ data }) => {
        if (cancelled) return
        setAsset(data)
        setLoadError(null)
      })
      .catch((err) => {
        if (cancelled) return
        setLoadError(err instanceof ApiError && err.status === 404 ? 'not_found' : 'error')
      })
    return () => {
      cancelled = true
    }
  }, [assetId, isValidId, reloadKey])

  const error = isValidId ? loadError : 'not_found'

  if (error) {
    return (
      <div className="flex flex-col gap-3">
        <p role="alert" className="text-sm text-destructive">
          {error === 'not_found' ? 'No se encontró el activo.' : 'No fue posible cargar el activo.'}
        </p>
        <BackLink>Volver a Activos</BackLink>
      </div>
    )
  }

  if (!asset) {
    return <Skeleton className="h-64 w-full" />
  }

  const usefulLife = asset.useful_life_months
  const expiry = dueState(asset.expires_on, 0)

  return (
    <div className="flex flex-col gap-4">
      <BackLink />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold tabular-nums">{asset.plate}</h1>
            <AssetStatusBadge status={asset.status} />
          </div>
          <p className="text-sm text-muted-foreground">
            {asset.asset_type.name} · {asset.asset_type.category.name}
          </p>
        </div>
        {canManage && (
          <Button variant="outline" onClick={() => setIsFormOpen(true)}>
            <PencilIcon data-icon="inline-start" />
            Editar
          </Button>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_280px]">
        <section aria-labelledby="asset-data-title">
          <Card>
            <CardHeader>
              <CardTitle>
                <h2 id="asset-data-title">Datos del activo</h2>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Field label="Tipo">{asset.asset_type.name}</Field>
                <Field label="Categoría">{asset.asset_type.category.name}</Field>
                <Field label="Serial">{asset.serial ?? empty}</Field>
                <Field label="Marca">{asset.brand ?? empty}</Field>
                <Field label="Modelo">{asset.model ?? empty}</Field>
                <Field label="Fecha de adquisición">{asset.acquisition_date ? formatDate(asset.acquisition_date) : empty}</Field>
                <Field label="Valor de adquisición">{asset.acquisition_value !== null ? formatMoney(asset.acquisition_value) : empty}</Field>
                <Field label="Vida útil">{usefulLife ? `${usefulLife} ${usefulLife === 1 ? 'mes' : 'meses'}` : empty}</Field>
                <Field label="Vencimiento">
                  {asset.expires_on ? (
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                      <span>{formatDate(asset.expires_on)}</span>
                      {asset.status !== 'retired' && <DueBadge state={expiry} />}
                    </span>
                  ) : (
                    empty
                  )}
                </Field>
                <Field label="Ubicación">
                  {asset.location ? (
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                      <span>{asset.location.name}</span>
                      {!asset.location.is_active && <Badge variant="secondary">Inactiva</Badge>}
                    </span>
                  ) : (
                    empty
                  )}
                </Field>
                <Field label="Lo tiene">{asset.holder ? `${asset.holder.full_name} · ${asset.holder.position}` : empty}</Field>
                <Field label="Próximo mantenimiento">{asset.next_maintenance_on ? formatDate(asset.next_maintenance_on) : empty}</Field>
              </dl>
              {asset.notes && (
                <div className="mt-4 flex flex-col gap-0.5">
                  <p className="text-xs text-muted-foreground">Observaciones</p>
                  <p className="text-sm whitespace-pre-line">{asset.notes}</p>
                </div>
              )}
            </CardContent>
          </Card>
        </section>

        <Card>
          <CardContent className="flex h-full items-center justify-center">
            {asset.photo_url ? (
              <img src={asset.photo_url} alt={`Foto del activo ${asset.plate}`} className="max-h-72 w-full rounded-lg object-contain" />
            ) : (
              <div className="flex flex-col items-center gap-2 py-8 text-muted-foreground">
                <ImageIcon className="size-8" aria-hidden />
                <span className="text-sm">Sin foto</span>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {canManage && <AssetStatusActions asset={asset} options={options} optionsFailed={optionsFailed} onChanged={reload} />}

      <MaintenanceSection assetId={asset.id} canManage={canManage} onChanged={reload} />

      <AssetHistory assetId={asset.id} reloadKey={reloadKey} />

      {canManage && (
        <AssetFormDialog
          open={isFormOpen}
          onOpenChange={setIsFormOpen}
          asset={asset}
          options={options}
          optionsFailed={optionsFailed}
          onSaved={(saved) => {
            setIsFormOpen(false)
            toast.success(`Activo "${saved.plate}" actualizado.`)
            reload()
          }}
        />
      )}
    </div>
  )
}
