import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { chooseOption, page } from '@/features/activos/test-fixtures'
import { EntregasScreen } from './EntregasScreen'
import { delivery } from './test-fixtures'

const list = vi.fn()
const alerts = vi.fn()
const getEmployees = vi.fn()
const can = vi.fn()
const useRequireAuth = vi.fn()

vi.mock('app/features/entregas/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/entregas/api')>()),
  deliveriesApi: {
    list: (...args: unknown[]) => list(...args),
    alerts: () => alerts(),
    get: vi.fn(),
    create: vi.fn(),
    createReturn: vi.fn(),
  },
}))
vi.mock('app/features/empleados/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/empleados/api')>()),
  employeesOptions: () => getEmployees(),
}))
vi.mock('app/provider/auth', () => ({
  useCan: (code: string) => can(code),
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))

const deliveries = [
  delivery(),
  delivery({
    id: 2,
    code: 'ENT-000002',
    employee: { id: 11, full_name: 'Luis Gómez', position: 'Cabo' },
    kind: 'prestamo',
    kind_label: 'Préstamo',
    due_on: '2026-10-10',
    status: 'overdue',
  }),
  delivery({ id: 3, code: 'ENT-000003', status: 'closed' }),
]

const baseParams = {
  search: '',
  employeeId: undefined,
  kind: undefined,
  status: undefined,
  from: undefined,
  to: undefined,
  page: 1,
  perPage: 25,
}

describe('EntregasScreen (/entregas, 007 US3, FR-006)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    can.mockReturnValue(true)
    list.mockResolvedValue(page(deliveries))
    alerts.mockResolvedValue({ data: [] })
    getEmployees.mockResolvedValue({
      data: [
        { id: 10, full_name: 'Ana Pérez', position: 'Bombero' },
        { id: 11, full_name: 'Luis Gómez', position: 'Cabo' },
      ],
    })
  })

  it('exige entregas.ver y lista código, empleado, tipo, fechas e insignia de estado', async () => {
    render(<EntregasScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('entregas.ver')
    expect(screen.getByRole('heading', { name: 'Entregas', level: 1 })).toBeInTheDocument()

    const first = await screen.findByRole('row', { name: /ENT-000001/ })
    expect(within(first).getByRole('link', { name: 'ENT-000001' })).toHaveAttribute('href', '/entregas/1')
    expect(within(first).getByText('Ana Pérez')).toBeInTheDocument()
    expect(within(first).getByText('Bombero')).toBeInTheDocument()
    expect(within(first).getByText('Dotación')).toBeInTheDocument()
    expect(within(first).getByText('Abierta')).toBeInTheDocument()

    const overdue = screen.getByRole('row', { name: /ENT-000002/ })
    expect(within(overdue).getByText('Préstamo')).toBeInTheDocument()
    expect(within(overdue).getByText('10 oct 2026')).toBeInTheDocument()
    expect(within(overdue).getByText('Vencida')).toBeInTheDocument()

    expect(within(screen.getByRole('row', { name: /ENT-000003/ })).getByText('Cerrada')).toBeInTheDocument()
    expect(list).toHaveBeenCalledWith(baseParams)
  })

  it('sin entregas.ver muestra "Acceso denegado" y no consulta', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<EntregasScreen />)

    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(list).not.toHaveBeenCalled()
    expect(alerts).not.toHaveBeenCalled()
  })

  it('busca por código o empleado con espera y vuelve a la página 1', async () => {
    render(<EntregasScreen />)
    await screen.findByRole('row', { name: /ENT-000001/ })

    fireEvent.change(screen.getByRole('searchbox', { name: 'Buscar entregas' }), { target: { value: 'ENT-000002' } })
    await waitFor(() => expect(list).toHaveBeenLastCalledWith({ ...baseParams, search: 'ENT-000002' }))
  })

  it('filtra por empleado, tipo, estado y fechas', async () => {
    render(<EntregasScreen />)
    await screen.findByRole('row', { name: /ENT-000001/ })

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por empleado' }), 'Luis Gómez · Cabo')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith({ ...baseParams, employeeId: 11 }))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por tipo' }), 'Préstamo')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith({ ...baseParams, employeeId: 11, kind: 'prestamo' }))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por estado' }), 'Vencida')
    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith({ ...baseParams, employeeId: 11, kind: 'prestamo', status: 'overdue' })
    )

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } })
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-17' } })
    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith({
        ...baseParams,
        employeeId: 11,
        kind: 'prestamo',
        status: 'overdue',
        from: '2026-10-01',
        to: '2026-10-17',
      })
    )
  })

  it('"Nueva entrega" solo con entregas.gestionar', async () => {
    render(<EntregasScreen />)
    expect(await screen.findByRole('link', { name: /Nueva entrega/ })).toHaveAttribute('href', '/entregas/nueva')
  })

  it('sin entregas.gestionar no ofrece "Nueva entrega"', async () => {
    can.mockImplementation((code: string) => code !== 'entregas.gestionar')
    render(<EntregasScreen />)
    await screen.findByRole('row', { name: /ENT-000001/ })
    expect(screen.queryByRole('link', { name: /Nueva entrega/ })).not.toBeInTheDocument()
  })

  it('muestra los préstamos vencidos de las alertas (FR-005, RN-026)', async () => {
    alerts.mockResolvedValue({
      data: [
        {
          id: 2,
          code: 'ENT-000002',
          employee: { id: 11, full_name: 'Luis Gómez', position: 'Cabo' },
          due_on: '2026-10-10',
          pending_quantity: 3,
        },
      ],
    })
    render(<EntregasScreen />)

    const card = await screen.findByRole('region', { name: 'Préstamos vencidos' })
    expect(within(card).getByRole('link', { name: 'ENT-000002' })).toHaveAttribute('href', '/entregas/2')
    expect(within(card).getByText(/Luis Gómez/)).toBeInTheDocument()
    expect(within(card).getByText(/3 pendientes/)).toBeInTheDocument()
  })

  it('informa si no se pudo cargar el listado', async () => {
    list.mockRejectedValue(new Error('boom'))
    render(<EntregasScreen />)
    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar las entregas.')
  })

  it('no ofrece editar ni borrar entregas (FR-001)', async () => {
    render(<EntregasScreen />)
    await screen.findByRole('row', { name: /ENT-000001/ })
    expect(screen.queryByRole('button', { name: /Editar|Eliminar|Borrar/ })).not.toBeInTheDocument()
  })
})
