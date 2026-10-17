import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { LocationTypeRecord } from 'app/features/configuracion/api'
import type { Paginated } from 'app/lib/api-helpers'
import { LocationTypesTab } from './LocationTypesTab'

const list = vi.fn()
const create = vi.fn()
const updateStatus = vi.fn()
const toastSuccess = vi.fn()
const can = vi.fn()

vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  locationTypesApi: {
    list: (...args: unknown[]) => list(...args),
    create: (...args: unknown[]) => create(...args),
    update: vi.fn(),
    updateStatus: (...args: unknown[]) => updateStatus(...args),
  },
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

function locationType(overrides: Partial<LocationTypeRecord>): LocationTypeRecord {
  return {
    id: 1,
    name: 'Bodega',
    description: null,
    is_active: true,
    locations_count: 1,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

function page<T>(data: T[]): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } }
}

describe('LocationTypesTab (US3, FR-003, RN-004)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page([locationType({}), locationType({ id: 4, name: 'Oficina', is_active: false, locations_count: 3 })]))
  })

  it('lista tipos de ubicación con número de ubicaciones y estado', async () => {
    render(<LocationTypesTab />)

    const oficina = await screen.findByRole('row', { name: /Oficina/ })
    expect(within(oficina).getByText('3')).toBeInTheDocument()
    expect(within(oficina).getByText('Inactivo')).toBeInTheDocument()
    expect(screen.getByRole('searchbox', { name: 'Buscar tipos de ubicación' })).toBeInTheDocument()
  })

  it('crea un tipo de ubicación', async () => {
    create.mockResolvedValue({ data: locationType({ id: 5, name: 'Vehículo' }) })
    render(<LocationTypesTab />)
    await screen.findByRole('row', { name: /Oficina/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo tipo de ubicación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo tipo de ubicación' })
    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Vehículo')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(create).toHaveBeenCalledWith({ name: 'Vehículo', description: null })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Tipo de ubicación "Vehículo" creado.'))
  })

  it('desactiva tras confirmar, conservando sus ubicaciones (FR-008)', async () => {
    updateStatus.mockResolvedValue({ data: locationType({ is_active: false }) })
    render(<LocationTypesTab />)
    await screen.findByRole('row', { name: /Bodega/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Bodega' }))
    const confirm = await screen.findByRole('alertdialog')
    expect(within(confirm).getByText(/ubicaciones se conservan/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))

    expect(updateStatus).toHaveBeenCalledWith(1, false)
  })
})
