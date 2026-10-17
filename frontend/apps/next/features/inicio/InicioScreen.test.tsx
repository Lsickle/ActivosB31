import { render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { dashboard } from '@/features/reportes/test-fixtures'
import { InicioScreen } from './InicioScreen'

const useRequireAuth = vi.fn()
const getDashboard = vi.fn()

vi.mock('app/provider/auth', () => ({
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))
vi.mock('app/features/reportes/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/reportes/api')>()),
  dashboardApi: { get: () => getDashboard() },
}))

describe('InicioScreen (/, 008 US4, D-004)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false, user: { name: 'Ana Admin' } })
    getDashboard.mockResolvedValue({ data: dashboard() })
  })

  it('no exige un permiso de módulo: solo sesión', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: false, user: null })
    const { container } = render(<InicioScreen />)
    expect(useRequireAuth).toHaveBeenCalledWith()
    expect(container).toBeEmptyDOMElement()
  })

  it('muestra los conteos de activos por estado y las alertas enlazadas a la ficha', async () => {
    render(<InicioScreen />)

    const assets = await screen.findByRole('region', { name: 'Activos' })
    expect(within(assets).getByText('60')).toBeInTheDocument()
    expect(within(within(assets).getByText('Disponible').closest('div')!).getByText('40')).toBeInTheDocument()
    expect(within(within(assets).getByText('Dado de baja').closest('div')!).getByText('5')).toBeInTheDocument()

    const alerts = screen.getByRole('region', { name: 'Alertas de activos' })
    expect(within(alerts).getByRole('link', { name: 'B31-0001' })).toHaveAttribute('href', '/activos/7')
    expect(within(alerts).getByRole('link', { name: 'B31-0002' })).toHaveAttribute('href', '/activos/8')
    expect(within(alerts).getByText('Vencido')).toBeInTheDocument()
  })

  it('muestra entregas abiertas, préstamos vencidos enlazados y tipos bajo mínimo enlazados a Inventario', async () => {
    render(<InicioScreen />)

    const deliveries = await screen.findByRole('region', { name: 'Entregas' })
    expect(within(within(deliveries).getByText('Entregas abiertas').closest('div')!).getByText('7')).toBeInTheDocument()
    expect(within(within(deliveries).getByText('Préstamos vencidos').closest('div')!).getByText('1')).toBeInTheDocument()
    expect(within(deliveries).getByRole('link', { name: 'ENT-000021' })).toHaveAttribute('href', '/entregas/21')
    expect(within(deliveries).getByText(/Ana Gómez/)).toBeInTheDocument()

    const inventory = screen.getByRole('region', { name: 'Inventario bajo mínimo' })
    expect(within(inventory).getByRole('link', { name: 'Guantes de rescate' })).toHaveAttribute('href', '/inventario')
    expect(within(inventory).getByText('15 de 20 mínimo')).toBeInTheDocument()
  })

  it('cada sección aparece solo si viene en la respuesta (permisos)', async () => {
    getDashboard.mockResolvedValue({ data: { deliveries: dashboard().deliveries } })
    render(<InicioScreen />)

    expect(await screen.findByRole('region', { name: 'Entregas' })).toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Activos' })).not.toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Alertas de activos' })).not.toBeInTheDocument()
    expect(screen.queryByRole('region', { name: 'Inventario bajo mínimo' })).not.toBeInTheDocument()
  })

  it('sin alertas muestra mensajes positivos', async () => {
    getDashboard.mockResolvedValue({
      data: dashboard({
        assets: { by_status: { available: 1, assigned: 0, maintenance: 0, retired: 0 }, total: 1, alerts: { maintenance: [], expirations: [] } },
        inventory: { below_min: [] },
        deliveries: { open_count: 0, overdue_count: 0, overdue: [] },
      }),
    })
    render(<InicioScreen />)

    expect(await screen.findByText('Todo en orden: no hay alertas pendientes.')).toBeInTheDocument()
    expect(screen.getByText('No hay mantenimientos ni vencimientos próximos.')).toBeInTheDocument()
    expect(screen.getByText('Ningún artículo está por debajo de su stock mínimo.')).toBeInTheDocument()
    expect(screen.getByText('No hay préstamos vencidos.')).toBeInTheDocument()
  })

  it('sin secciones (ningún módulo con indicadores) lo explica, y avisa si falla la carga', async () => {
    getDashboard.mockResolvedValueOnce({ data: {} })
    const { unmount } = render(<InicioScreen />)
    expect(await screen.findByText('No tienes módulos con indicadores para mostrar en el tablero.')).toBeInTheDocument()
    unmount()

    getDashboard.mockRejectedValueOnce(new Error('boom'))
    render(<InicioScreen />)
    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar el tablero.')
  })
})
