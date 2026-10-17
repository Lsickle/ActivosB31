import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PositionRecord } from 'app/features/configuracion/api'
import type { Paginated } from 'app/lib/api-helpers'
import { PositionsTab } from './PositionsTab'

const list = vi.fn()
const create = vi.fn()
const updateStatus = vi.fn()
const toastSuccess = vi.fn()
const can = vi.fn()

vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  positionsApi: {
    list: (...args: unknown[]) => list(...args),
    create: (...args: unknown[]) => create(...args),
    update: vi.fn(),
    updateStatus: (...args: unknown[]) => updateStatus(...args),
  },
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

function position(overrides: Partial<PositionRecord>): PositionRecord {
  return {
    id: 1,
    name: 'Bombero',
    description: null,
    is_active: true,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

function page<T>(data: T[]): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } }
}

// specs/004-modulo-empleados US2 / FR-004 / RN-035.
describe('PositionsTab (004 US2, FR-004)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page([position({}), position({ id: 2, name: 'Teniente', is_active: false })]))
  })

  it('lista los cargos con su estado', async () => {
    render(<PositionsTab />)

    const teniente = await screen.findByRole('row', { name: /Teniente/ })
    expect(within(teniente).getByText('Inactivo')).toBeInTheDocument()
    expect(screen.getByRole('searchbox', { name: 'Buscar cargos' })).toBeInTheDocument()
    expect(list).toHaveBeenCalledWith({ search: '', isActive: undefined, page: 1, perPage: 25 })
  })

  it('crea un cargo', async () => {
    create.mockResolvedValue({ data: position({ id: 5, name: 'Sargento' }) })
    render(<PositionsTab />)
    await screen.findByRole('row', { name: /Teniente/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo cargo' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo cargo' })
    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Sargento')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(create).toHaveBeenCalledWith({ name: 'Sargento', description: null })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Cargo "Sargento" creado.'))
  })

  it('desactiva tras confirmar; los empleados lo conservan (US2-1)', async () => {
    updateStatus.mockResolvedValue({ data: position({ is_active: false }) })
    render(<PositionsTab />)
    await screen.findByRole('row', { name: /Bombero/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Bombero' }))
    const confirm = await screen.findByRole('alertdialog')
    expect(within(confirm).getByText(/empleados que ya lo tienen lo conservan/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))

    expect(updateStatus).toHaveBeenCalledWith(1, false)
  })

  it('sin configuracion.gestionar no ofrece acciones', async () => {
    can.mockReturnValue(false)
    render(<PositionsTab />)
    await screen.findByRole('row', { name: /Teniente/ })

    expect(screen.queryByRole('button', { name: 'Nuevo cargo' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Desactivar/ })).not.toBeInTheDocument()
  })
})
