import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { page } from '@/features/activos/test-fixtures'
import { InventarioScreen } from './InventarioScreen'
import { options, stockRow } from './test-fixtures'

const stock = vi.fn()
const summary = vi.fn()
const getOptions = vi.fn()
const can = vi.fn()
const useRequireAuth = vi.fn()

vi.mock('app/features/inventario/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/inventario/api')>()),
  inventoryApi: {
    stock: (...args: unknown[]) => stock(...args),
    serializedSummary: () => summary(),
    movements: { list: vi.fn().mockResolvedValue({ data: [], meta: null }), create: vi.fn() },
    counts: { list: vi.fn().mockResolvedValue({ data: [], meta: null }), get: vi.fn(), create: vi.fn(), updateLines: vi.fn(), close: vi.fn() },
    alerts: vi.fn(),
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

describe('InventarioScreen (/inventario, 006 FR-006)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    can.mockReturnValue(false)
    stock.mockResolvedValue(page([stockRow()]))
    summary.mockResolvedValue({ data: [] })
    getOptions.mockResolvedValue({ data: options })
  })

  it('exige inventario.ver y muestra las pestañas; abre en Existencias', async () => {
    render(<InventarioScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('inventario.ver')
    expect(screen.getByRole('heading', { name: 'Inventario' })).toBeInTheDocument()
    const tabs = within(screen.getByRole('tablist'))
    for (const name of ['Existencias', 'Movimientos', 'Conteos', 'Serializados']) {
      expect(tabs.getByRole('tab', { name })).toBeInTheDocument()
    }
    expect(await screen.findByRole('row', { name: /Guantes de rescate/ })).toBeInTheDocument()

    await userEvent.click(tabs.getByRole('tab', { name: 'Serializados' }))
    expect(summary).toHaveBeenCalled()
  })

  it('sin inventario.ver muestra "Acceso denegado"', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<InventarioScreen />)
    expect(screen.getByText(/Acceso denegado/i)).toBeInTheDocument()
    expect(stock).not.toHaveBeenCalled()
  })
})
