'use client'

import { useEffect, useMemo, useState } from 'react'
import { ArrowLeftIcon, PlusIcon, TriangleAlertIcon, XIcon } from 'lucide-react'
import { toast } from 'sonner'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import { inventoryApi, type InventoryCount } from 'app/features/inventario/api'
import { COUNT_STATUS_LABELS, countLinesSchema, type CountLineValues } from 'app/features/inventario/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { formatDateTime } from '@/features/activos/format'
import { formatDifference, formatQuantity, muted, quantityTypeItems } from './shared'

type NamedRef = { id: number; name: string }

// Renglón en edición. `counted` guarda el texto del input (vacío = sin contar).
type DraftLine = {
  key: string
  id?: number
  asset_type: NamedRef | null
  asset: { id: number; plate: string } | null
  expected: number
  counted: string
  found: boolean
}

function draftLines(count: InventoryCount): DraftLine[] {
  return count.lines.map((line) => ({
    key: String(line.id),
    id: line.id,
    asset_type: line.asset_type,
    asset: line.asset,
    expected: line.expected_quantity ?? 0,
    counted: line.counted_quantity === null ? '' : String(line.counted_quantity),
    // Serializados sin revisar (null) arrancan como no encontrados.
    found: line.found ?? false,
  }))
}

// Forma del PATCH .../lines (plan.md): serializados con `found`; cantidades
// con `counted_quantity`; renglones nuevos por `asset_type_id`.
function linesPayload(lines: DraftLine[]) {
  return lines.map((line) => {
    if (line.asset) return { id: line.id, asset_id: line.asset.id, found: line.found }
    if (line.id) return { id: line.id, counted_quantity: line.counted }
    return { asset_type_id: line.asset_type?.id, counted_quantity: line.counted }
  })
}

function lineName(line: { asset_type: NamedRef | null }): string {
  return line.asset_type?.name ?? 'Tipo sin nombre'
}

const COUNTED_REQUIRED = 'Registra la cantidad contada de todos los renglones antes de cerrar.'

// US3 / D-004: detalle de un conteo. Abierto + inventario.gestionar: se
// registran contados y encontrados, se agregan tipos sin existencia, se guarda
// y se cierra. Cerrado (o sin permiso): solo lectura con su resultado.
export function CountDetail({
  countId,
  canManage,
  options,
  onBack,
}: {
  countId: number
  canManage: boolean
  options: ConfigurationOptions | null
  onBack: () => void
}) {
  const [count, setCount] = useState<InventoryCount | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [lines, setLines] = useState<DraftLine[]>([])
  const [lineErrors, setLineErrors] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [confirmOpen, setConfirmOpen] = useState(false)
  const [changedSinceOpen, setChangedSinceOpen] = useState(false)
  const [typeToAdd, setTypeToAdd] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    inventoryApi.counts
      .get(countId)
      .then(({ data }) => {
        if (cancelled) return
        setCount(data)
        setLines(draftLines(data))
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [countId])

  const editable = canManage && count?.status === 'open'

  // Tipos por cantidad que aún no tienen renglón (D-004: "tipos sin existencia").
  const addItems = useMemo(() => {
    const present = new Set(lines.filter((line) => !line.asset && line.asset_type).map((line) => line.asset_type!.id))
    return quantityTypeItems(options, present)
  }, [options, lines])

  function updateLine(key: string, change: Partial<DraftLine>) {
    setLines((current) => current.map((line) => (line.key === key ? { ...line, ...change } : line)))
    setLineErrors((current) => {
      if (!current[key]) return current
      return Object.fromEntries(Object.entries(current).filter(([lineKey]) => lineKey !== key))
    })
  }

  function addLine() {
    const type = options?.asset_types.find((option) => String(option.id) === typeToAdd)
    if (!type) return
    setLines((current) => [
      ...current,
      { key: `new-${type.id}`, asset_type: { id: type.id, name: type.name }, asset: null, expected: 0, counted: '', found: false },
    ])
    setTypeToAdd(null)
  }

  function removeLine(key: string) {
    setLines((current) => current.filter((line) => line.key !== key))
  }

  // Guarda los renglones; devuelve el conteo actualizado o null si falló.
  async function save(): Promise<InventoryCount | null> {
    setFormError(null)
    const parsed = countLinesSchema.safeParse(linesPayload(lines))
    if (!parsed.success) {
      const errors: Record<string, string> = {}
      for (const issue of parsed.error.issues) {
        const line = lines[Number(issue.path[0])]
        if (line) errors[line.key] ??= issue.message
      }
      setLineErrors(errors)
      return null
    }

    setLineErrors({})
    setIsSaving(true)
    try {
      const { data } = await inventoryApi.counts.updateLines(countId, parsed.data as CountLineValues[])
      setCount(data)
      setLines(draftLines(data))
      return data
    } catch (err) {
      if (err instanceof ApiValidationError) {
        // 422 de Laravel con claves `lines.N.campo`.
        const errors: Record<string, string> = {}
        const general: string[] = []
        for (const [field, messages] of Object.entries(err.errors)) {
          const match = /^lines\.(\d+)\./.exec(field)
          const line = match ? lines[Number(match[1])] : undefined
          if (line && messages[0]) errors[line.key] ??= messages[0]
          else if (messages[0]) general.push(messages[0])
        }
        setLineErrors(errors)
        setFormError(general[0] ?? (Object.keys(errors).length ? null : err.message))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible guardar el conteo.')
      }
      return null
    } finally {
      setIsSaving(false)
    }
  }

  async function handleSave() {
    if (await save()) toast.success('Conteo guardado.')
  }

  function requestClose() {
    const pending = lines.filter((line) => !line.asset && line.counted.trim() === '')
    if (pending.length > 0) {
      setFormError(COUNTED_REQUIRED)
      setLineErrors(Object.fromEntries(pending.map((line) => [line.key, 'Falta la cantidad contada.'])))
      return
    }
    setFormError(null)
    setConfirmOpen(true)
  }

  async function confirmClose(): Promise<boolean> {
    if (!(await save())) return false
    try {
      const { data } = await inventoryApi.counts.close(countId)
      setCount(data)
      setLines(draftLines(data))
      setChangedSinceOpen(data.changed_since_open === true)
      toast.success('Conteo cerrado.')
      return true
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No fue posible cerrar el conteo.')
      return false
    }
  }

  const backButton = (
    <Button type="button" variant="ghost" size="sm" className="self-start" onClick={onBack}>
      <ArrowLeftIcon data-icon="inline-start" />
      Volver a los conteos
    </Button>
  )

  if (loadError) {
    return (
      <div className="flex flex-col gap-3">
        {backButton}
        <p role="alert" className="text-sm text-destructive">
          No fue posible cargar el conteo.
        </p>
      </div>
    )
  }

  if (!count) {
    return (
      <div className="flex flex-col gap-3">
        {backButton}
        <Skeleton className="h-48 w-full" />
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {backButton}
      <div className="flex flex-col gap-1">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="text-lg font-semibold">
            Conteo #{count.id} · {count.location.name}
          </h2>
          <Badge variant={count.status === 'open' ? 'default' : 'outline'}>{COUNT_STATUS_LABELS[count.status]}</Badge>
        </div>
        <p className="text-sm text-muted-foreground">
          Abierto {formatDateTime(count.opened_at)}
          {count.opened_by && ` por ${count.opened_by.name}`}
          {count.closed_at && ` · Cerrado ${formatDateTime(count.closed_at)}`}
          {count.closed_at && count.closed_by && ` por ${count.closed_by.name}`}
        </p>
        {count.notes && <p className="text-sm">{count.notes}</p>}
      </div>

      {count.status === 'closed' && <CountResult lines={lines} changedSinceOpen={changedSinceOpen} />}

      <div className="overflow-x-auto rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead scope="col">Artículo</TableHead>
              <TableHead scope="col" className="text-right">
                Esperado
              </TableHead>
              <TableHead scope="col">Contado</TableHead>
              <TableHead scope="col" className="text-right">
                Diferencia
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {lines.length === 0 ? (
              <TableRow>
                <TableCell colSpan={4} className="h-20 text-center text-muted-foreground">
                  No hay nada esperado en esta ubicación. Agrega los tipos que encuentres.
                </TableCell>
              </TableRow>
            ) : (
              lines.map((line) => (
                <CountLineRow
                  key={line.key}
                  line={line}
                  editable={editable}
                  error={lineErrors[line.key]}
                  onChange={(change) => updateLine(line.key, change)}
                  onRemove={line.id ? undefined : () => removeLine(line.key)}
                />
              ))
            )}
          </TableBody>
        </Table>
      </div>

      {editable && (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <Select items={addItems} value={typeToAdd} onValueChange={(value) => setTypeToAdd(value as string | null)}>
            <SelectTrigger aria-label="Tipo a agregar" className="sm:w-72">
              <SelectValue placeholder={addItems.length ? 'Tipo por cantidad sin renglón' : 'No hay más tipos por agregar'} />
            </SelectTrigger>
            <SelectContent>
              {addItems.map((item) => (
                <SelectItem key={item.value} value={item.value}>
                  {item.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button type="button" variant="outline" disabled={!typeToAdd} onClick={addLine}>
            <PlusIcon data-icon="inline-start" />
            Agregar renglón
          </Button>
        </div>
      )}

      {formError && (
        <p role="alert" className="text-sm text-destructive">
          {formError}
        </p>
      )}

      {editable && (
        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" variant="outline" disabled={isSaving} onClick={handleSave}>
            {isSaving ? 'Guardando…' : 'Guardar'}
          </Button>
          <Button type="button" disabled={isSaving} onClick={requestClose}>
            Cerrar conteo
          </Button>
        </div>
      )}

      <ConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        title="Cerrar conteo"
        description="Se guardarán los renglones y se registrarán ajustes por las diferencias de cantidad. Lo esperado se recalcula con la existencia actual. Los activos no encontrados solo se reportan: su estado no cambia. El conteo cerrado no se puede reabrir."
        confirmLabel="Cerrar conteo"
        onConfirm={confirmClose}
      />
    </div>
  )
}

function CountLineRow({
  line,
  editable,
  error,
  onChange,
  onRemove,
}: {
  line: DraftLine
  editable: boolean
  error?: string
  onChange: (change: Partial<DraftLine>) => void
  onRemove?: () => void
}) {
  const errorId = `count-line-${line.key}-error`

  if (line.asset) {
    const label = `Encontrado ${line.asset.plate}`
    return (
      <TableRow>
        <TableCell>
          <div className="flex flex-col">
            <span className="font-medium tabular-nums">{line.asset.plate}</span>
            {line.asset_type && <span className="text-xs text-muted-foreground">{line.asset_type.name}</span>}
          </div>
        </TableCell>
        <TableCell className="text-right text-muted-foreground">Debe estar</TableCell>
        <TableCell>
          {editable ? (
            <label className="flex items-center gap-2 text-sm">
              <Checkbox aria-label={label} checked={line.found} onCheckedChange={(checked) => onChange({ found: checked === true })} />
              Encontrado
            </label>
          ) : line.found ? (
            'Encontrado'
          ) : (
            <Badge variant="destructive">No encontrado</Badge>
          )}
        </TableCell>
        <TableCell className="text-right">{muted}</TableCell>
      </TableRow>
    )
  }

  const name = lineName(line)
  const counted = line.counted.trim() === '' ? null : Number(line.counted)
  const difference = counted !== null && Number.isInteger(counted) ? counted - line.expected : null

  return (
    <TableRow>
      <TableCell>
        <div className="flex items-center gap-2">
          <span className="font-medium">{name}</span>
          {!line.id && <Badge variant="secondary">Nuevo</Badge>}
          {editable && onRemove && (
            <Button type="button" size="icon-xs" variant="ghost" aria-label={`Quitar ${name}`} onClick={onRemove}>
              <XIcon />
            </Button>
          )}
        </div>
      </TableCell>
      <TableCell className="text-right tabular-nums">{formatQuantity(line.expected)}</TableCell>
      <TableCell>
        {editable ? (
          <div className="flex flex-col gap-1">
            <Input
              type="number"
              inputMode="numeric"
              min={0}
              step={1}
              aria-label={`Contado de ${name}`}
              aria-invalid={error ? true : undefined}
              aria-describedby={error ? errorId : undefined}
              value={line.counted}
              onChange={(event) => onChange({ counted: event.target.value })}
              className="w-28"
            />
            {error && (
              <p id={errorId} className="text-xs text-destructive">
                {error}
              </p>
            )}
          </div>
        ) : counted === null ? (
          muted
        ) : (
          <span className="tabular-nums">{formatQuantity(counted)}</span>
        )}
      </TableCell>
      <TableCell className="text-right tabular-nums">
        {difference === null ? muted : <span className={difference === 0 ? undefined : 'font-medium'}>{formatDifference(difference)}</span>}
      </TableCell>
    </TableRow>
  )
}

// Resultado del cierre (RN-027, RN-037, edge case 4): ajustes por diferencia y
// activos no encontrados. Se deriva de los renglones del conteo cerrado.
function CountResult({ lines, changedSinceOpen }: { lines: DraftLine[]; changedSinceOpen: boolean }) {
  const adjustments = lines
    .filter((line) => !line.asset && line.counted.trim() !== '')
    .map((line) => ({ line, difference: Number(line.counted) - line.expected }))
    .filter(({ difference }) => difference !== 0)
  const missing = lines.filter((line) => line.asset && !line.found)

  return (
    <section aria-labelledby="count-result-title" className="flex flex-col gap-3 rounded-lg border p-4">
      <h3 id="count-result-title" className="font-medium">
        Resultado del conteo
      </h3>
      {changedSinceOpen && (
        <p className="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400">
          <TriangleAlertIcon className="mt-0.5 size-4 shrink-0" aria-hidden />
          Las existencias cambiaron desde la apertura: lo esperado se recalculó con la existencia al momento del cierre.
        </p>
      )}
      <div className="flex flex-col gap-1 text-sm">
        <span className="font-medium">Ajustes generados</span>
        {adjustments.length === 0 ? (
          <span className="text-muted-foreground">Sin diferencias de cantidad: no se generaron ajustes.</span>
        ) : (
          <ul className="list-disc pl-5">
            {adjustments.map(({ line, difference }) => (
              <li key={line.key}>
                {lineName(line)}: {formatDifference(difference)} (esperado {formatQuantity(line.expected)}, contado{' '}
                {formatQuantity(Number(line.counted))})
              </li>
            ))}
          </ul>
        )}
      </div>
      <div className="flex flex-col gap-1 text-sm">
        <span className="font-medium">Activos no encontrados</span>
        {missing.length === 0 ? (
          <span className="text-muted-foreground">Todos los activos esperados fueron encontrados.</span>
        ) : (
          <>
            <ul className="list-disc pl-5">
              {missing.map((line) => (
                <li key={line.key}>
                  <span className="tabular-nums">{line.asset!.plate}</span>
                  {line.asset_type && <span className="text-muted-foreground"> · {line.asset_type.name}</span>}
                </li>
              ))}
            </ul>
            <span className="text-muted-foreground">Se reportan para revisión; su estado no cambia automáticamente.</span>
          </>
        )}
      </div>
    </section>
  )
}
