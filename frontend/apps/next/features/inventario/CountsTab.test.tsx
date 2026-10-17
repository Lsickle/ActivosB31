import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError } from 'app/lib/api-client'
import { chooseOption, page } from '@/features/activos/test-fixtures'
import { CountsTab } from './CountsTab'
import { count, options } from './test-fixtures'

const list = vi.fn()
const get = vi.fn()
const create = vi.fn()
const updateLines = vi.fn()
const close = vi.fn()
const can = vi.fn()

vi.mock('app/features/inventario/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/inventario/api')>()),
  inventoryApi: {
    counts: {
      list: (...args: unknown[]) => list(...args),
      get: (...args: unknown[]) => get(...args),
      create: (...args: unknown[]) => create(...args),
      updateLines: (...args: unknown[]) => updateLines(...args),
      close: (...args: unknown[]) => close(...args),
    },
  },
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const closedCount = count({
  id: 10,
  status: 'closed',
  location: { id: 8, name: 'Máquina 1' },
  closed_by: { id: 1, name: 'Almacenista' },
  closed_at: '2026-10-16T18:00:00Z',
  lines: [
    { id: 90, asset_type: { id: 5, name: 'Guantes de rescate' }, asset: null, expected_quantity: 15, counted_quantity: 13, found: null },
    { id: 91, asset_type: { id: 3, name: 'Cilindro SCBA' }, asset: { id: 7, plate: 'B31-0001' }, expected_quantity: null, counted_quantity: null, found: false },
  ],
})

async function openCount(name: RegExp) {
  const row = await screen.findByRole('row', { name })
  await userEvent.click(within(row).getByRole('button', { name: /Ver conteo|Continuar conteo/ }))
}

describe('CountsTab (006 US3, RN-027, RN-037)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page([count(), closedCount]))
    get.mockImplementation(async (id: number) => ({ data: id === 10 ? closedCount : count() }))
  })

  it('lista los conteos con ubicación, estado y quién los abrió', async () => {
    render(<CountsTab options={options} optionsFailed={false} />)

    const open = await screen.findByRole('row', { name: /Bodega principal/ })
    expect(within(open).getByText('Abierto')).toBeInTheDocument()
    expect(within(open).getByText(/Almacenista/)).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Máquina 1/ })).getByText('Cerrado')).toBeInTheDocument()
    expect(list).toHaveBeenCalledWith({ page: 1, perPage: 25 })
  })

  it('sin inventario.gestionar no ofrece abrir conteos ni editar renglones', async () => {
    can.mockReturnValue(false)
    render(<CountsTab options={options} optionsFailed={false} />)
    await openCount(/Bodega principal/)

    expect(await screen.findByText('Guantes de rescate')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Nuevo conteo' })).not.toBeInTheDocument()
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cerrar conteo' })).not.toBeInTheDocument()
  })

  it('abre un conteo eligiendo la ubicación; un 422 (ya hay uno abierto) va junto al campo (US3-3)', async () => {
    create.mockRejectedValueOnce(new ApiValidationError('x', { location_id: ['Ya hay un conteo abierto en esta ubicación.'] }))
    create.mockResolvedValueOnce({ data: count({ id: 13, location: { id: 8, name: 'Máquina 1' } }) })
    get.mockResolvedValue({ data: count({ id: 13, location: { id: 8, name: 'Máquina 1' } }) })
    render(<CountsTab options={options} optionsFailed={false} />)
    await screen.findByRole('row', { name: /Bodega principal/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo conteo' }))
    const dialog = await screen.findByRole('dialog')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Abrir conteo' }))
    expect(await within(dialog).findByText('Selecciona la ubicación a contar.')).toBeInTheDocument()

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Ubicación' }), 'Máquina 1')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Abrir conteo' }))
    expect(await within(dialog).findByText('Ya hay un conteo abierto en esta ubicación.')).toBeInTheDocument()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Abrir conteo' }))
    await waitFor(() => expect(create).toHaveBeenLastCalledWith({ location_id: 8, notes: null }))
    expect(await screen.findByRole('heading', { name: /Conteo #13 · Máquina 1/ })).toBeInTheDocument()
  })

  it('registra cantidades contadas, encontrados y un renglón de un tipo sin existencia; guarda los renglones', async () => {
    updateLines.mockResolvedValue({ data: count() })
    render(<CountsTab options={options} optionsFailed={false} />)
    await openCount(/Bodega principal/)

    const gloves = await screen.findByRole('row', { name: /Guantes de rescate/ })
    expect(within(gloves).getByRole('cell', { name: '15' })).toBeInTheDocument()
    await userEvent.type(within(gloves).getByRole('spinbutton', { name: 'Contado de Guantes de rescate' }), '13')
    expect(within(gloves).getByText('−2')).toBeInTheDocument()

    const cylinder = screen.getByRole('row', { name: /B31-0001/ })
    await userEvent.click(within(cylinder).getByRole('checkbox', { name: 'Encontrado B31-0001' }))

    // Se ofrecen solo tipos por cantidad que aún no tienen renglón.
    await chooseOption(screen.getByRole('combobox', { name: 'Tipo a agregar' }), /Cinta de señalización/)
    expect(screen.queryByRole('option', { name: /Guantes de rescate/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Cilindro SCBA/ })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Agregar renglón' }))
    await userEvent.type(screen.getByRole('spinbutton', { name: 'Contado de Cinta de señalización' }), '4')

    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() =>
      expect(updateLines).toHaveBeenCalledWith(12, [
        { id: 101, counted_quantity: 13 },
        { id: 102, asset_id: 7, found: true },
        { asset_type_id: 6, counted_quantity: 4 },
      ])
    )
  })

  it('no deja cerrar con renglones sin contar', async () => {
    render(<CountsTab options={options} optionsFailed={false} />)
    await openCount(/Bodega principal/)
    await screen.findByRole('row', { name: /Guantes de rescate/ })

    await userEvent.click(screen.getByRole('button', { name: 'Cerrar conteo' }))
    expect(await screen.findByText('Registra la cantidad contada de todos los renglones antes de cerrar.')).toBeInTheDocument()
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(close).not.toHaveBeenCalled()
  })

  it('cierra con confirmación: guarda, cierra y resume ajustes, no encontrados (sin cambio de estado) y cambios desde la apertura (US3-1, US3-2, US3-4)', async () => {
    updateLines.mockResolvedValue({ data: count() })
    close.mockResolvedValue({
      data: {
        ...closedCount,
        id: 12,
        location: { id: 4, name: 'Bodega principal' },
        changed_since_open: true,
        lines: [
          { id: 101, asset_type: { id: 5, name: 'Guantes de rescate' }, asset: null, expected_quantity: 16, counted_quantity: 13, found: null },
          { id: 102, asset_type: { id: 3, name: 'Cilindro SCBA' }, asset: { id: 7, plate: 'B31-0001' }, expected_quantity: null, counted_quantity: null, found: false },
        ],
      },
    })
    render(<CountsTab options={options} optionsFailed={false} />)
    await openCount(/Bodega principal/)

    const gloves = await screen.findByRole('row', { name: /Guantes de rescate/ })
    await userEvent.type(within(gloves).getByRole('spinbutton', { name: 'Contado de Guantes de rescate' }), '13')
    await userEvent.click(screen.getByRole('button', { name: 'Cerrar conteo' }))

    const confirm = await screen.findByRole('alertdialog')
    expect(within(confirm).getByText(/su estado no cambia/i)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Cerrar conteo' }))

    await waitFor(() => expect(close).toHaveBeenCalledWith(12))
    expect(updateLines).toHaveBeenCalledWith(12, [
      { id: 101, counted_quantity: 13 },
      { id: 102, asset_id: 7, found: false },
    ])

    const result = await screen.findByRole('region', { name: 'Resultado del conteo' })
    expect(within(result).getByText(/las existencias cambiaron desde la apertura/i)).toBeInTheDocument()
    expect(within(result).getByText(/Guantes de rescate: −3/)).toBeInTheDocument()
    expect(within(result).getByText('B31-0001')).toBeInTheDocument()
    expect(within(result).getByText(/no cambia automáticamente/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cerrar conteo' })).not.toBeInTheDocument()
  })

  it('un conteo cerrado se ve en solo lectura con su resultado', async () => {
    render(<CountsTab options={options} optionsFailed={false} />)
    await openCount(/Máquina 1/)

    const result = await screen.findByRole('region', { name: 'Resultado del conteo' })
    expect(within(result).getByText(/Guantes de rescate: −2/)).toBeInTheDocument()
    expect(within(result).getByText('B31-0001')).toBeInTheDocument()
    expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Guardar' })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Volver a los conteos' }))
    expect(await screen.findByRole('button', { name: 'Nuevo conteo' })).toBeInTheDocument()
  })
})
