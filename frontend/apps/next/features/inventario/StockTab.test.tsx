import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { chooseOption, page } from '@/features/activos/test-fixtures'
import { StockTab } from './StockTab'
import { options, stockRow } from './test-fixtures'

const stock = vi.fn()

vi.mock('app/features/inventario/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/inventario/api')>()),
  inventoryApi: { stock: (...args: unknown[]) => stock(...args) },
}))

const rows = [
  stockRow(),
  stockRow({
    asset_type: { id: 6, name: 'Cinta de señalización', category: { id: 2, name: 'Señalización' } },
    location: { id: 9, name: 'Bodega vieja', is_active: false },
    quantity: 3,
    type_total: 8,
    min_stock: 10,
    below_min: true,
  }),
]

describe('StockTab (006 US2, RN-028)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    stock.mockResolvedValue(page(rows))
  })

  it('lista existencia por tipo y ubicación con total del tipo, mínimo e insignia "Bajo mínimo"', async () => {
    render(<StockTab options={options} optionsFailed={false} />)

    const first = await screen.findByRole('row', { name: /Guantes de rescate/ })
    expect(within(first).getByText('EPP')).toBeInTheDocument()
    expect(within(first).getByText('Bodega principal')).toBeInTheDocument()
    expect(within(first).getByRole('cell', { name: '15' })).toBeInTheDocument()
    expect(within(first).getByRole('cell', { name: '20' })).toBeInTheDocument()
    expect(within(first).getByRole('cell', { name: '10' })).toBeInTheDocument()
    expect(within(first).queryByText('Bajo mínimo')).not.toBeInTheDocument()

    const second = screen.getByRole('row', { name: /Cinta de señalización/ })
    expect(within(second).getByText('Bajo mínimo')).toBeInTheDocument()
    expect(within(second).getByText('Inactiva')).toBeInTheDocument()

    expect(stock).toHaveBeenCalledWith({
      assetCategoryId: undefined,
      assetTypeId: undefined,
      locationId: undefined,
      belowMin: false,
      page: 1,
      perPage: 25,
    })
  })

  it('filtra por categoría, tipo (solo por cantidad), ubicación y "Solo bajo mínimo" (US2-1)', async () => {
    render(<StockTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Guantes de rescate/ })

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por categoría' }), 'EPP')
    await waitFor(() => expect(stock).toHaveBeenLastCalledWith(expect.objectContaining({ assetCategoryId: 1 })))

    // RN-007: no se ofrecen los serializados; con categoría EPP, solo sus tipos.
    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por tipo' }), 'Guantes de rescate')
    expect(screen.queryByRole('option', { name: 'Cilindro SCBA' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Cinta de señalización' })).not.toBeInTheDocument()
    await waitFor(() => expect(stock).toHaveBeenLastCalledWith(expect.objectContaining({ assetTypeId: 5 })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por ubicación' }), 'Máquina 1')
    await waitFor(() => expect(stock).toHaveBeenLastCalledWith(expect.objectContaining({ locationId: 8 })))

    await userEvent.click(screen.getByRole('checkbox', { name: 'Solo bajo mínimo' }))
    await waitFor(() =>
      expect(stock).toHaveBeenLastCalledWith({ assetCategoryId: 1, assetTypeId: 5, locationId: 8, belowMin: true, page: 1, perPage: 25 })
    )
  })

  it('muestra un error si no carga', async () => {
    stock.mockRejectedValue(new Error('boom'))
    render(<StockTab options={options} optionsFailed={false} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar las existencias.')
  })
})
