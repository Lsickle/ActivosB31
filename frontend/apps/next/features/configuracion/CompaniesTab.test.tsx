import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CompanyRecord } from 'app/features/configuracion/api'
import type { Paginated } from 'app/lib/api-helpers'
import { CompaniesTab } from './CompaniesTab'

const list = vi.fn()
const create = vi.fn()
const updateStatus = vi.fn()
const toastSuccess = vi.fn()
const can = vi.fn()

vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  companiesApi: {
    list: (...args: unknown[]) => list(...args),
    create: (...args: unknown[]) => create(...args),
    update: vi.fn(),
    updateStatus: (...args: unknown[]) => updateStatus(...args),
  },
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

function company(overrides: Partial<CompanyRecord>): CompanyRecord {
  return {
    id: 1,
    name: 'Compañía A',
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

// specs/004-modulo-empleados US2 / FR-004 / RN-036.
describe('CompaniesTab (004 US2, FR-004)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page([company({}), company({ id: 2, name: 'Turno B', is_active: false })]))
  })

  it('lista las compañías con su estado', async () => {
    render(<CompaniesTab />)

    const turnoB = await screen.findByRole('row', { name: /Turno B/ })
    expect(within(turnoB).getByText('Inactiva')).toBeInTheDocument()
    expect(screen.getByRole('searchbox', { name: 'Buscar compañías' })).toBeInTheDocument()
    expect(list).toHaveBeenCalledWith({ search: '', isActive: undefined, page: 1, perPage: 25 })
  })

  it('crea una compañía', async () => {
    create.mockResolvedValue({ data: company({ id: 5, name: 'Turno C' }) })
    render(<CompaniesTab />)
    await screen.findByRole('row', { name: /Turno B/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nueva compañía' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nueva compañía' })
    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Turno C')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(create).toHaveBeenCalledWith({ name: 'Turno C', description: null })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Compañía "Turno C" creada.'))
  })

  it('desactiva tras confirmar; los empleados la conservan (US2-1)', async () => {
    updateStatus.mockResolvedValue({ data: company({ is_active: false }) })
    render(<CompaniesTab />)
    await screen.findByRole('row', { name: /Compañía A/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Compañía A' }))
    const confirm = await screen.findByRole('alertdialog')
    expect(within(confirm).getByText(/empleados que ya la tienen la conservan/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))

    expect(updateStatus).toHaveBeenCalledWith(1, false)
  })

  it('sin configuracion.gestionar no ofrece acciones', async () => {
    can.mockReturnValue(false)
    render(<CompaniesTab />)
    await screen.findByRole('row', { name: /Turno B/ })

    expect(screen.queryByRole('button', { name: 'Nueva compañía' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Desactivar/ })).not.toBeInTheDocument()
  })
})
