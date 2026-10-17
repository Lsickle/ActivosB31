import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ConfigurationOptions, LocationRecord } from 'app/features/configuracion/api'
import type { Paginated } from 'app/lib/api-helpers'
import { LocationsTab } from './LocationsTab'

const list = vi.fn()
const create = vi.fn()
const update = vi.fn()
const getOptions = vi.fn()
const toastSuccess = vi.fn()
const can = vi.fn()

vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  locationsApi: {
    list: (...args: unknown[]) => list(...args),
    create: (...args: unknown[]) => create(...args),
    update: (...args: unknown[]) => update(...args),
    updateStatus: vi.fn(),
  },
  getConfigurationOptions: () => getOptions(),
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

function location(overrides: Partial<LocationRecord>): LocationRecord {
  return {
    id: 1,
    name: 'Bodega principal',
    description: null,
    is_active: true,
    location_type: { id: 1, name: 'Bodega', is_active: true },
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

function page<T>(data: T[]): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } }
}

// Solo tipos activos (FR-007): "Oficina" (id 4) está inactivo.
const options: ConfigurationOptions = {
  asset_categories: [],
  asset_types: [],
  location_types: [
    { id: 1, name: 'Bodega' },
    { id: 2, name: 'Vehículo' },
  ],
  locations: [],
  positions: [],
  companies: [],
}

const locations = [location({}), location({ id: 2, name: 'Recepción', location_type: { id: 4, name: 'Oficina', is_active: false } })]

async function chooseOption(combobox: HTMLElement, name: string) {
  fireEvent.click(combobox)
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

describe('LocationsTab (US3, FR-004, RN-002)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page(locations))
    getOptions.mockResolvedValue({ data: options })
  })

  it('muestra el tipo de cada ubicación y marca los tipos inactivos', async () => {
    render(<LocationsTab />)

    const recepcion = await screen.findByRole('row', { name: /Recepción/ })
    expect(within(recepcion).getByText('Oficina')).toBeInTheDocument()
    expect(within(recepcion).getByText('Tipo inactivo')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Bodega principal/ })).getByText('Bodega')).toBeInTheDocument()
  })

  it('filtra por tipo de ubicación', async () => {
    render(<LocationsTab />)
    await screen.findByRole('row', { name: /Recepción/ })

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por tipo de ubicación' }), 'Vehículo')

    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ locationTypeId: 2, page: 1 })))
  })

  it('crea una ubicación con un tipo activo (US3 escenario 2)', async () => {
    create.mockResolvedValue({ data: location({ id: 7, name: 'Máquina 1' }) })
    render(<LocationsTab />)
    await screen.findByRole('row', { name: /Recepción/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nueva ubicación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nueva ubicación' })
    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Máquina 1')

    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Tipo de ubicación' }))
    expect(await screen.findByRole('option', { name: 'Vehículo' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Oficina/ })).not.toBeInTheDocument()
    await act(async () => {
      const option = screen.getByRole('option', { name: 'Vehículo' })
      fireEvent.pointerDown(option)
      fireEvent.click(option)
    })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(create).toHaveBeenCalledWith({ name: 'Máquina 1', description: null, location_type_id: 2 })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Ubicación "Máquina 1" creada.'))
  })

  it('al editar conserva el tipo actual aunque esté inactivo (US3 escenario 4)', async () => {
    update.mockResolvedValue({ data: locations[1] })
    render(<LocationsTab />)
    await screen.findByRole('row', { name: /Recepción/ })

    await userEvent.click(screen.getByRole('button', { name: 'Editar Recepción' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar ubicación' })
    expect(within(dialog).getByRole('combobox', { name: 'Tipo de ubicación' })).toHaveTextContent('Oficina (inactivo)')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(update).toHaveBeenCalledWith(2, { name: 'Recepción', description: null, location_type_id: 4 })
  })

  it('sin tipos de ubicación activos avisa en lugar del formulario (US3 escenario 1)', async () => {
    getOptions.mockResolvedValue({ data: { ...options, location_types: [] } })
    render(<LocationsTab />)
    await screen.findByRole('row', { name: /Recepción/ })
    await waitFor(() => expect(getOptions).toHaveBeenCalled())

    await userEvent.click(screen.getByRole('button', { name: 'Nueva ubicación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nueva ubicación' })

    expect(within(dialog).getByText('Primero crea un tipo de ubicación activo.')).toBeInTheDocument()
    expect(within(dialog).queryByLabelText('Nombre')).not.toBeInTheDocument()
  })
})
