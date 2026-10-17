import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError } from 'app/lib/api-client'
import { chooseOption, page } from '@/features/activos/test-fixtures'
import { MovementsTab } from './MovementsTab'
import { movement, options, stockRow } from './test-fixtures'

const list = vi.fn()
const create = vi.fn()
const stock = vi.fn()
const can = vi.fn()
const toastSuccess = vi.fn()

vi.mock('app/features/inventario/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/inventario/api')>()),
  inventoryApi: {
    stock: (...args: unknown[]) => stock(...args),
    movements: { list: (...args: unknown[]) => list(...args), create: (...args: unknown[]) => create(...args) },
  },
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

const movements = [
  movement(),
  movement({
    id: 2,
    type: 'transfer',
    type_label: 'Traslado',
    quantity: 5,
    from_location: { id: 4, name: 'Bodega principal' },
    to_location: { id: 8, name: 'Máquina 1' },
    reason: null,
  }),
  movement({ id: 3, type: 'adjustment', type_label: 'Ajuste', quantity: 2, from_location: { id: 4, name: 'Bodega principal' }, to_location: null, reason: 'Diferencia de conteo', count_id: 12 }),
]

async function openDialog() {
  await userEvent.click(screen.getByRole('button', { name: 'Registrar movimiento' }))
  return screen.findByRole('dialog')
}

describe('MovementsTab (006 US1, FR-001, FR-003)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page(movements))
    stock.mockResolvedValue(page([stockRow(), stockRow({ location: { id: 9, name: 'Bodega vieja', is_active: false }, quantity: 0 })]))
  })

  it('lista el historial con fecha, clase, tipo, cantidad, origen, destino, motivo y usuario; sin editar ni borrar', async () => {
    render(<MovementsTab options={options} optionsFailed={false} />)

    const transfer = await screen.findByRole('row', { name: /Traslado/ })
    expect(within(transfer).getByText('Guantes de rescate')).toBeInTheDocument()
    expect(within(transfer).getByRole('cell', { name: '5' })).toBeInTheDocument()
    expect(within(transfer).getByText('Bodega principal')).toBeInTheDocument()
    expect(within(transfer).getByText('Máquina 1')).toBeInTheDocument()
    expect(within(transfer).getByText('Almacenista')).toBeInTheDocument()

    const adjustment = screen.getByRole('row', { name: /Ajuste/ })
    expect(within(adjustment).getByText('Diferencia de conteo')).toBeInTheDocument()
    expect(within(adjustment).getByText('Conteo #12')).toBeInTheDocument()

    // US1-4: no hay opción de editar ni borrar; se corrige con un movimiento inverso.
    expect(screen.queryByRole('button', { name: /editar|eliminar|borrar/i })).not.toBeInTheDocument()
    expect(screen.getByText(/no se editan ni se borran/)).toBeInTheDocument()

    expect(list).toHaveBeenCalledWith({
      type: undefined,
      assetTypeId: undefined,
      locationId: undefined,
      from: undefined,
      to: undefined,
      page: 1,
      perPage: 25,
    })
  })

  it('filtra por clase de movimiento, tipo de artículo, ubicación y rango de fechas', async () => {
    render(<MovementsTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Traslado/ })

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por clase de movimiento' }), 'Pérdida')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ type: 'loss' })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por tipo de artículo' }), 'Guantes de rescate')
    expect(screen.queryByRole('option', { name: 'Cilindro SCBA' })).not.toBeInTheDocument()
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ assetTypeId: 5 })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por ubicación' }), 'Máquina 1')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ locationId: 8 })))

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } })
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-17' } })
    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith({
        type: 'loss',
        assetTypeId: 5,
        locationId: 8,
        from: '2026-10-01',
        to: '2026-10-17',
        page: 1,
        perPage: 25,
      })
    )
  })

  it('sin inventario.gestionar no ofrece registrar movimientos (FR-006)', async () => {
    can.mockReturnValue(false)
    render(<MovementsTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Traslado/ })
    expect(can).toHaveBeenCalledWith('inventario.gestionar')
    expect(screen.queryByRole('button', { name: 'Registrar movimiento' })).not.toBeInTheDocument()
  })

  it('registra una entrada: solo tipos por cantidad (RN-007) y destino entre ubicaciones activas (US1-1)', async () => {
    create.mockResolvedValue({ data: movement({ id: 9 }) })
    render(<MovementsTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Traslado/ })
    const dialog = await openDialog()
    expect(within(dialog).getByText(/movimiento inverso/)).toBeInTheDocument()

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Clase de movimiento' }), 'Entrada')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Tipo de artículo' }), /Guantes de rescate/)
    expect(screen.queryByRole('option', { name: /Cilindro SCBA/ })).not.toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText('Cantidad'), '20')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Ubicación de destino' }), 'Bodega principal')
    await userEvent.type(within(dialog).getByLabelText(/Motivo/), 'Compra')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar' }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith({ type: 'entry', asset_type_id: 5, quantity: 20, to_location_id: 4, reason: 'Compra' })
    )
    expect(toastSuccess).toHaveBeenCalledWith('Movimiento registrado.')
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2))
  })

  it('salida: el origen ofrece las ubicaciones con existencia del tipo; muestra "Existencia insuficiente" junto a la cantidad (US1-2)', async () => {
    create.mockRejectedValue(new ApiValidationError('Existencia insuficiente', { quantity: ['Existencia insuficiente.'] }))
    render(<MovementsTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Traslado/ })
    const dialog = await openDialog()

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Clase de movimiento' }), 'Salida')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Tipo de artículo' }), /Guantes de rescate/)
    await waitFor(() => expect(stock).toHaveBeenCalledWith({ assetTypeId: 5, perPage: 100 }))
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Ubicación de origen' }), /Bodega principal \(15 disponibles\)/)
    // Sin existencia no se ofrece como origen.
    expect(screen.queryByRole('option', { name: /Bodega vieja/ })).not.toBeInTheDocument()
    await userEvent.type(within(dialog).getByLabelText('Cantidad'), '25')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar' }))

    // Motivo obligatorio en salidas (FR-001).
    expect(await within(dialog).findByText('Indica el motivo.')).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()

    await userEvent.type(within(dialog).getByLabelText(/Motivo/), 'Daño')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar' }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith({ type: 'exit', asset_type_id: 5, quantity: 25, from_location_id: 4, reason: 'Daño' })
    )
    expect(await within(dialog).findByText('Existencia insuficiente.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Cantidad')).toHaveAttribute('aria-invalid', 'true')
  })

  it('traslado: rechaza origen y destino iguales', async () => {
    render(<MovementsTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Traslado/ })
    const dialog = await openDialog()

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Clase de movimiento' }), 'Traslado')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Tipo de artículo' }), /Guantes de rescate/)
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Ubicación de origen' }), /Bodega principal/)
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Ubicación de destino' }), 'Bodega principal')
    await userEvent.type(within(dialog).getByLabelText('Cantidad'), '5')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar' }))

    expect(await within(dialog).findByText('El destino debe ser distinto del origen.')).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()
  })

  it('ajuste: "Disminuir" envía la ubicación como origen; los errores del servidor van al campo de ubicación', async () => {
    create.mockRejectedValueOnce(new ApiValidationError('x', { from_location_id: ['La ubicación no es válida.'] }))
    render(<MovementsTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Traslado/ })
    const dialog = await openDialog()

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Clase de movimiento' }), 'Ajuste')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Tipo de artículo' }), /Guantes de rescate/)
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Dirección del ajuste' }), 'Disminuir')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Ubicación' }), /Bodega principal/)
    await userEvent.type(within(dialog).getByLabelText('Cantidad'), '2')
    await userEvent.type(within(dialog).getByLabelText(/Motivo/), 'Faltante')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar' }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith({ type: 'adjustment', asset_type_id: 5, quantity: 2, from_location_id: 4, reason: 'Faltante' })
    )
    expect(await within(dialog).findByText('La ubicación no es válida.')).toBeInTheDocument()
  })
})
