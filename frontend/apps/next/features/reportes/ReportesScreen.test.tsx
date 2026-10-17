import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { options } from '@/features/inventario/test-fixtures'
import { ReportesScreen } from './ReportesScreen'
import { inventoryReport } from './test-fixtures'

const useRequireAuth = vi.fn()
const getOptions = vi.fn()
const inventory = vi.fn()

vi.mock('app/provider/auth', () => ({
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))
vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  getConfigurationOptions: () => getOptions(),
}))
vi.mock('app/features/reportes/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/reportes/api')>()),
  reportsApi: {
    inventory: (...args: unknown[]) => inventory(...args),
    employee: vi.fn(),
    asset: vi.fn(),
    assetType: vi.fn(),
    assetOptions: vi.fn().mockResolvedValue({ data: [] }),
    employeeOptions: vi.fn().mockResolvedValue({ data: [] }),
    assetTypeOptions: vi.fn().mockResolvedValue({ data: [] }),
    download: vi.fn(),
  },
}))
vi.mock('./download', () => ({ saveFile: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

describe('ReportesScreen (/reportes, 008 FR-003)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    getOptions.mockResolvedValue({ data: options })
    inventory.mockResolvedValue({ data: inventoryReport() })
  })

  it('exige reportes.ver', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<ReportesScreen />)
    expect(useRequireAuth).toHaveBeenCalledWith('reportes.ver')
    expect(screen.getByText('Acceso denegado')).toBeInTheDocument()
  })

  it('tiene las pestañas Inventario general, Por empleado y Por artículo', async () => {
    render(<ReportesScreen />)

    expect(screen.getByRole('heading', { name: 'Reportes' })).toBeInTheDocument()
    expect(await screen.findByRole('table', { name: 'Activos serializados' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Por empleado' }))
    expect(await screen.findByRole('searchbox', { name: 'Buscar empleado' })).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Por artículo' }))
    expect(await screen.findByRole('combobox', { name: 'Clase de artículo' })).toBeInTheDocument()
  })
})
