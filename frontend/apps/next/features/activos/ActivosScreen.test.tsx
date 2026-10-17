import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ActivosScreen } from './ActivosScreen'
import { asset, chooseOption, options, page } from './test-fixtures'

const list = vi.fn()
const alerts = vi.fn()
const getOptions = vi.fn()
const can = vi.fn()
const useRequireAuth = vi.fn()

vi.mock('app/features/activos/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/activos/api')>()),
  assetsApi: {
    list: (...args: unknown[]) => list(...args),
    alerts: () => alerts(),
    create: vi.fn(),
    update: vi.fn(),
    uploadPhoto: vi.fn(),
    deletePhoto: vi.fn(),
  },
}))
vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  getConfigurationOptions: () => getOptions(),
}))
vi.mock('app/provider/auth', () => ({
  useCan: (code: string) => can(code),
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

const assets = [
  asset({ next_maintenance_on: '2027-03-10' }),
  asset({
    id: 8,
    plate: 'B31-0002',
    brand: 'Motorola',
    model: 'APX',
    status: 'assigned',
    status_label: 'Asignado',
    asset_type: { id: 6, name: 'Radio portátil', tracking_mode: 'serialized', category: { id: 2, name: 'Comunicaciones' } },
    location: null,
    holder: { id: 10, full_name: 'Ana Pérez', position: 'Bombero' },
  }),
]

describe('ActivosScreen (/activos, 005 US1, US5, FR-007)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    can.mockReturnValue(true)
    list.mockResolvedValue(page(assets))
    alerts.mockResolvedValue({ data: { maintenance: [], expirations: [] } })
    getOptions.mockResolvedValue({ data: options })
  })

  it('exige activos.ver y lista placa, tipo, marca/modelo, estado, ubicación, quién lo tiene y próximo mantenimiento', async () => {
    render(<ActivosScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('activos.ver')
    expect(screen.getByRole('heading', { name: 'Activos' })).toBeInTheDocument()

    const first = await screen.findByRole('row', { name: /B31-0001/ })
    expect(within(first).getByRole('link', { name: 'B31-0001' })).toHaveAttribute('href', '/activos/7')
    expect(within(first).getByText('Cilindro SCBA')).toBeInTheDocument()
    expect(within(first).getByText('EPP')).toBeInTheDocument()
    expect(within(first).getByText('MSA · G1')).toBeInTheDocument()
    expect(within(first).getByText('Disponible')).toBeInTheDocument()
    expect(within(first).getByText('Bodega principal')).toBeInTheDocument()
    expect(within(first).getByText('10 mar 2027')).toBeInTheDocument()

    const second = screen.getByRole('row', { name: /B31-0002/ })
    expect(within(second).getByText('Asignado')).toBeInTheDocument()
    expect(within(second).getByText('Ana Pérez')).toBeInTheDocument()

    expect(list).toHaveBeenCalledWith({
      search: '',
      assetCategoryId: undefined,
      assetTypeId: undefined,
      status: undefined,
      locationId: undefined,
      page: 1,
      perPage: 25,
    })
  })

  it('busca y filtra por categoría, tipo (solo serializados), estado y ubicación (US1-5)', async () => {
    render(<ActivosScreen />)
    await screen.findByRole('row', { name: /B31-0001/ })

    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar activos' }), 'msa')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'msa', page: 1 })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por categoría' }), 'EPP')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ assetCategoryId: 1 })))

    // RN-007: los tipos por cantidad no se ofrecen; con categoría EPP, solo sus tipos.
    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por tipo' }), 'Cilindro SCBA')
    expect(screen.queryByRole('option', { name: 'Guantes de rescate' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Radio portátil' })).not.toBeInTheDocument()
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ assetTypeId: 3 })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por estado' }), 'En mantenimiento')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'maintenance' })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por ubicación' }), 'Máquina 1')
    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith(
        expect.objectContaining({ search: 'msa', assetCategoryId: 1, assetTypeId: 3, status: 'maintenance', locationId: 8 })
      )
    )
  })

  it('pagina con el servidor', async () => {
    list.mockResolvedValue(page(assets, { last_page: 2, total: 30 }))
    render(<ActivosScreen />)
    await screen.findByText(/Página 1 de 2 · 30 activos/)

    await userEvent.click(screen.getByRole('button', { name: 'Página siguiente' }))
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })))
  })

  it('muestra la tarjeta de alertas con mantenimientos y vencimientos y enlace a la ficha (US5, D-004)', async () => {
    alerts.mockResolvedValue({
      data: {
        maintenance: [
          {
            plan_id: 3,
            plan_name: 'Prueba hidrostática',
            next_due_on: '2026-10-01',
            overdue: true,
            asset: { id: 7, plate: 'B31-0001', asset_type: { id: 3, name: 'Cilindro SCBA' } },
          },
        ],
        expirations: [
          { expires_on: '2026-11-06', overdue: false, asset: { id: 9, plate: 'B31-0100', asset_type: { id: 1, name: 'Casco' } } },
        ],
      },
    })
    render(<ActivosScreen />)

    const card = await screen.findByRole('region', { name: 'Alertas' })
    const maintenance = (await within(card).findByText(/Prueba hidrostática/)).closest('li')!
    expect(within(maintenance).getByRole('link', { name: 'B31-0001' })).toHaveAttribute('href', '/activos/7')
    expect(within(maintenance).getByText('Vencido')).toBeInTheDocument()
    expect(within(maintenance).getByText(/1 oct 2026/)).toBeInTheDocument()

    const expiration = within(card).getByRole('link', { name: 'B31-0100' }).closest('li')!
    expect(within(expiration).getByRole('link', { name: 'B31-0100' })).toHaveAttribute('href', '/activos/9')
    expect(within(expiration).getByText('Próximo a vencer')).toBeInTheDocument()
  })

  it('sin alertas lo indica', async () => {
    render(<ActivosScreen />)
    const card = await screen.findByRole('region', { name: 'Alertas' })
    expect(await within(card).findByText('No hay mantenimientos ni vencimientos próximos.')).toBeInTheDocument()
  })

  it('"Nuevo activo" solo con activos.gestionar (FR-007)', async () => {
    can.mockImplementation((code: string) => code !== 'activos.gestionar')
    render(<ActivosScreen />)
    await screen.findByRole('row', { name: /B31-0001/ })

    expect(can).toHaveBeenCalledWith('activos.gestionar')
    expect(screen.queryByRole('button', { name: 'Nuevo activo' })).not.toBeInTheDocument()
  })

  it('abre el formulario de nuevo activo', async () => {
    render(<ActivosScreen />)
    await screen.findByRole('row', { name: /B31-0001/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo activo' }))
    expect(await screen.findByRole('dialog', { name: 'Nuevo activo' })).toBeInTheDocument()
  })

  it('sin activos.ver muestra "Acceso denegado"', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<ActivosScreen />)

    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(list).not.toHaveBeenCalled()
  })
})
