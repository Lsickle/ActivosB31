'use client'

import { useEffect, useState } from 'react'
import Link from 'next/link'
import { employeeHoldings, type EmployeeHolding } from 'app/features/entregas/api'
import { DELIVERY_KIND_LABELS } from 'app/features/entregas/schemas'
import { useCan } from 'app/provider/auth'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatDate } from '@/features/activos/format'
import { DeliveryStatusBadge, formatQuantity, itemLabel, muted } from './shared'

// 007 US3-2 / D-007: lo que el empleado tiene asignado o prestado (ítems con
// pendientes). Se abre desde Empleados (requiere empleados.ver); el enlace a
// la entrega solo aparece con entregas.ver. `employee` = null → cerrado.
export function EmployeeHoldingsDialog({
  employee,
  onOpenChange,
}: {
  employee: { id: number; full_name: string } | null
  onOpenChange: (open: boolean) => void
}) {
  return (
    <Dialog open={employee !== null} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-3xl">
        {employee && <Holdings employee={employee} />}
      </DialogContent>
    </Dialog>
  )
}

function Holdings({ employee }: { employee: { id: number; full_name: string } }) {
  const canSeeDeliveries = useCan('entregas.ver')
  const [rows, setRows] = useState<EmployeeHolding[] | null>(null)
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    let cancelled = false
    employeeHoldings(employee.id)
      .then(({ data }) => {
        if (!cancelled) setRows(data)
      })
      .catch(() => {
        if (!cancelled) setFailed(true)
      })
    return () => {
      cancelled = true
    }
  }, [employee.id])

  return (
    <>
      <DialogHeader>
        <DialogTitle>Tenencias de {employee.full_name}</DialogTitle>
        <DialogDescription>Artículos que tiene asignados (dotación) o prestados y aún no ha devuelto.</DialogDescription>
      </DialogHeader>
      {failed ? (
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar las tenencias.
        </p>
      ) : rows === null ? (
        <Skeleton className="h-32 w-full" />
      ) : rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No tiene artículos asignados ni prestados.</p>
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <Table aria-label="Tenencias">
            <TableHeader>
              <TableRow>
                <TableHead scope="col">Artículo</TableHead>
                <TableHead scope="col">Pendiente</TableHead>
                <TableHead scope="col">Tipo</TableHead>
                <TableHead scope="col">Entrega</TableHead>
                <TableHead scope="col">Devolución esperada</TableHead>
                <TableHead scope="col">Estado</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rows.map((row) => (
                <TableRow key={row.delivery_item_id}>
                  <TableCell>{itemLabel(row)}</TableCell>
                  <TableCell className="tabular-nums">
                    {row.asset ? formatQuantity(row.pending_quantity) : `${formatQuantity(row.pending_quantity)} de ${formatQuantity(row.quantity)}`}
                  </TableCell>
                  <TableCell>{DELIVERY_KIND_LABELS[row.delivery.kind] ?? row.delivery.kind_label}</TableCell>
                  <TableCell className="tabular-nums">
                    {canSeeDeliveries ? (
                      <Link href={`/entregas/${row.delivery.id}`} className="underline-offset-4 hover:underline">
                        {row.delivery.code}
                      </Link>
                    ) : (
                      row.delivery.code
                    )}
                  </TableCell>
                  <TableCell>{row.delivery.due_on ? formatDate(row.delivery.due_on) : muted}</TableCell>
                  <TableCell>
                    <DeliveryStatusBadge status={row.delivery.status} />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </>
  )
}
