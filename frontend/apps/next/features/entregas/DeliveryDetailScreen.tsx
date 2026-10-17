'use client'
/* eslint-disable @next/next/no-img-element -- la firma es una URL temporal firmada (D-002): next/image no aplica. */

import { useCallback, useEffect, useState } from 'react'
import Link from 'next/link'
import { useParams } from 'next/navigation'
import { ArrowLeftIcon, PenLineIcon, Undo2Icon } from 'lucide-react'
import { toast } from 'sonner'
import { deliveriesApi, type DeliveryRecord } from 'app/features/entregas/api'
import { DELIVERY_KIND_LABELS } from 'app/features/entregas/schemas'
import { ApiError } from 'app/lib/api-client'
import { useCan, useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatDate, formatDateTime } from '@/features/activos/format'
import { useConfigurationOptions } from '@/features/configuracion/useConfigurationOptions'
import { ReturnDialog } from './ReturnDialog'
import { DeliveryStatusBadge, formatQuantity, muted } from './shared'

// Ficha de la entrega (/entregas/[id], spec 007 US2, US3): artículos,
// devoluciones y firma. Ver = entregas.ver; devolver = entregas.gestionar.
export function DeliveryDetailScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('entregas.ver')
  const params = useParams<{ id: string }>()
  const deliveryId = Number(params?.id)

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return <DeliveryDetail deliveryId={deliveryId} />
}

function BackLink({ children = 'Entregas' }: { children?: React.ReactNode }) {
  return (
    <Link href="/entregas" className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
      <ArrowLeftIcon className="size-4" aria-hidden />
      {children}
    </Link>
  )
}

function Field({ label, children, className }: { label: string; children: React.ReactNode; className?: string }) {
  return (
    <div className={className}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{children}</dd>
    </div>
  )
}

function DeliveryDetail({ deliveryId }: { deliveryId: number }) {
  const canManage = useCan('entregas.gestionar')
  const { options, failed: optionsFailed } = useConfigurationOptions()
  const [record, setRecord] = useState<DeliveryRecord | null>(null)
  const [loadError, setLoadError] = useState<'not_found' | 'error' | null>(null)
  const [reloadKey, setReloadKey] = useState(0)
  const [isReturnOpen, setIsReturnOpen] = useState(false)

  const reload = useCallback(() => setReloadKey((key) => key + 1), [])
  const isValidId = Number.isInteger(deliveryId) && deliveryId > 0

  useEffect(() => {
    if (!isValidId) return
    let cancelled = false
    deliveriesApi
      .get(deliveryId)
      .then(({ data }) => {
        if (cancelled) return
        setRecord(data)
        setLoadError(null)
      })
      .catch((err) => {
        if (!cancelled) setLoadError(err instanceof ApiError && err.status === 404 ? 'not_found' : 'error')
      })
    return () => {
      cancelled = true
    }
  }, [deliveryId, isValidId, reloadKey])

  const error = isValidId ? loadError : 'not_found'

  if (error) {
    return (
      <div className="flex flex-col gap-3">
        <p role="alert" className="text-sm text-destructive">
          {error === 'not_found' ? 'No se encontró la entrega.' : 'No fue posible cargar la entrega.'}
        </p>
        <BackLink>Volver a Entregas</BackLink>
      </div>
    )
  }

  if (!record) {
    return <Skeleton className="h-64 w-full" />
  }

  const hasPending = record.items.some((item) => item.pending_quantity > 0)
  const returns = record.items
    .flatMap((item) => item.returns.map((entry) => ({ entry, item })))
    .sort((a, b) => b.entry.returned_at.localeCompare(a.entry.returned_at))

  return (
    <div className="flex flex-col gap-4">
      <BackLink />
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex flex-col gap-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-xl font-semibold tabular-nums">{record.code}</h1>
            <DeliveryStatusBadge status={record.status} />
          </div>
          <p className="text-sm text-muted-foreground">
            {DELIVERY_KIND_LABELS[record.kind] ?? record.kind_label} · {record.employee.full_name}
          </p>
        </div>
        {canManage && hasPending && (
          <Button onClick={() => setIsReturnOpen(true)}>
            <Undo2Icon data-icon="inline-start" />
            Registrar devolución
          </Button>
        )}
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <section aria-labelledby="delivery-data-title">
          <Card className="h-full">
            <CardHeader>
              <CardTitle>
                <h2 id="delivery-data-title">Datos de la entrega</h2>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <dl className="grid gap-4 sm:grid-cols-2">
                {/* RN-022 / D-006: solo nombre y cargo; los datos personales viven en Empleados. */}
                <Field label="Empleado">{`${record.employee.full_name} · ${record.employee.position}`}</Field>
                <Field label="Tipo">{DELIVERY_KIND_LABELS[record.kind] ?? record.kind_label}</Field>
                <Field label="Devolución esperada">{record.due_on ? formatDate(record.due_on) : muted}</Field>
                <Field label="Ubicación de origen">{record.from_location.name}</Field>
                <Field label="Entregó">{record.delivered_by.name}</Field>
                <Field label="Fecha de entrega">{formatDateTime(record.delivered_at)}</Field>
                {record.notes && (
                  <Field label="Observaciones" className="sm:col-span-2">
                    <span className="whitespace-pre-line">{record.notes}</span>
                  </Field>
                )}
              </dl>
            </CardContent>
          </Card>
        </section>

        <section aria-labelledby="delivery-signature-title">
          <Card className="h-full">
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <PenLineIcon className="size-4" aria-hidden />
                <h2 id="delivery-signature-title">Firma</h2>
              </CardTitle>
            </CardHeader>
            <CardContent>
              {record.signature_url ? (
                <img
                  src={record.signature_url}
                  alt={`Firma de ${record.employee.full_name}`}
                  className="h-40 w-full rounded-lg border bg-white object-contain"
                />
              ) : (
                <p className="text-sm text-muted-foreground">No fue posible mostrar la firma.</p>
              )}
            </CardContent>
          </Card>
        </section>
      </div>

      <section aria-labelledby="delivery-items-title" className="flex flex-col gap-2">
        <h2 id="delivery-items-title" className="text-base font-medium">
          Artículos
        </h2>
        <div className="overflow-x-auto rounded-lg border">
          <Table aria-label="Artículos entregados">
            <TableHeader>
              <TableRow>
                <TableHead scope="col">Artículo</TableHead>
                <TableHead scope="col">Tipo</TableHead>
                <TableHead scope="col">Entregado</TableHead>
                <TableHead scope="col">Devuelto</TableHead>
                <TableHead scope="col">Pendiente</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {record.items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>
                    {item.asset ? (
                      <Link href={`/activos/${item.asset.id}`} className="font-medium tabular-nums underline-offset-4 hover:underline">
                        {item.asset.plate}
                      </Link>
                    ) : (
                      <span>{item.asset_type.name}</span>
                    )}
                  </TableCell>
                  <TableCell>{item.asset ? item.asset_type.name : <Badge variant="secondary">Por cantidad</Badge>}</TableCell>
                  <TableCell className="tabular-nums">{formatQuantity(item.quantity)}</TableCell>
                  <TableCell className="tabular-nums">{formatQuantity(item.returned_quantity)}</TableCell>
                  <TableCell className="tabular-nums">{formatQuantity(item.pending_quantity)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </section>

      <section aria-labelledby="delivery-returns-title" className="flex flex-col gap-2">
        <h2 id="delivery-returns-title" className="text-base font-medium">
          Devoluciones
        </h2>
        {returns.length === 0 ? (
          <p className="text-sm text-muted-foreground">Aún no hay devoluciones.</p>
        ) : (
          <div className="overflow-x-auto rounded-lg border">
            <Table aria-label="Devoluciones">
              <TableHeader>
                <TableRow>
                  <TableHead scope="col">Fecha</TableHead>
                  <TableHead scope="col">Artículo</TableHead>
                  <TableHead scope="col">Cantidad</TableHead>
                  <TableHead scope="col">Estado</TableHead>
                  <TableHead scope="col">Vuelve a</TableHead>
                  <TableHead scope="col">Recibió</TableHead>
                  <TableHead scope="col">Observación</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {returns.map(({ entry, item }) => (
                  <TableRow key={entry.id}>
                    <TableCell className="whitespace-nowrap">{formatDateTime(entry.returned_at)}</TableCell>
                    <TableCell>{item.asset ? `${item.asset_type.name} · ${item.asset.plate}` : item.asset_type.name}</TableCell>
                    <TableCell className="tabular-nums">{formatQuantity(entry.quantity)}</TableCell>
                    <TableCell>
                      <Badge variant={entry.condition === 'good' ? 'outline' : 'destructive'}>{entry.condition_label}</Badge>
                    </TableCell>
                    <TableCell>{entry.to_location?.name ?? muted}</TableCell>
                    <TableCell>{entry.received_by.name}</TableCell>
                    <TableCell className="max-w-64 whitespace-pre-line">{entry.notes ?? muted}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </section>

      {canManage && hasPending && (
        <ReturnDialog
          open={isReturnOpen}
          onOpenChange={setIsReturnOpen}
          delivery={record}
          options={options}
          optionsFailed={optionsFailed}
          onSaved={() => {
            setIsReturnOpen(false)
            toast.success('Devolución registrada.')
            reload()
          }}
        />
      )}
    </div>
  )
}
