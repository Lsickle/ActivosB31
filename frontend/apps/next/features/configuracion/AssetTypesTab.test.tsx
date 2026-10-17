import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AssetTypeRecord, ConfigurationOptions } from 'app/features/configuracion/api'
import type { Paginated } from 'app/lib/api-helpers'
import { ApiValidationError } from 'app/lib/api-client'
import { AssetTypesTab } from './AssetTypesTab'

const list = vi.fn()
const create = vi.fn()
const update = vi.fn()
const updateStatus = vi.fn()
const getOptions = vi.fn()
const toastSuccess = vi.fn()
const can = vi.fn()

vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  assetTypesApi: {
    list: (...args: unknown[]) => list(...args),
    create: (...args: unknown[]) => create(...args),
    update: (...args: unknown[]) => update(...args),
    updateStatus: (...args: unknown[]) => updateStatus(...args),
  },
  getConfigurationOptions: () => getOptions(),
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

function assetType(overrides: Partial<AssetTypeRecord>): AssetTypeRecord {
  return {
    id: 1,
    name: 'Casco estructural',
    description: null,
    tracking_mode: 'serialized',
    tracking_mode_label: 'Serializado',
    min_stock: null,
    is_active: true,
    category: { id: 1, name: 'EPP', is_active: true },
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

function page<T>(data: T[]): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } }
}

// Solo categorías activas (FR-007, FR-010): "Herramientas" (id 3) está inactiva.
const options: ConfigurationOptions = {
  asset_categories: [
    { id: 2, name: 'Comunicaciones' },
    { id: 1, name: 'EPP' },
  ],
  asset_types: [],
  location_types: [],
  locations: [],
  positions: [],
  companies: [],
}

const types = [
  assetType({}),
  assetType({ id: 2, name: 'Guantes de rescate', tracking_mode: 'quantity', tracking_mode_label: 'Por cantidad' }),
  assetType({ id: 3, name: 'Motosierra', category: { id: 3, name: 'Herramientas', is_active: false } }),
]

async function chooseOption(combobox: HTMLElement, name: string) {
  fireEvent.click(combobox)
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

describe('AssetTypesTab (US2, FR-002, RN-007)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page(types))
    getOptions.mockResolvedValue({ data: options })
  })

  it('muestra categoría, modo de control y estado', async () => {
    render(<AssetTypesTab />)

    const guantes = await screen.findByRole('row', { name: /Guantes de rescate/ })
    expect(within(guantes).getByText('EPP')).toBeInTheDocument()
    expect(within(guantes).getByText('Por cantidad')).toBeInTheDocument()
    expect(within(guantes).getByText('Activo')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Casco estructural/ })).getByText('Serializado')).toBeInTheDocument()
    expect(list).toHaveBeenCalledWith({
      search: '',
      isActive: undefined,
      page: 1,
      perPage: 25,
      assetCategoryId: undefined,
      trackingMode: undefined,
    })
  })

  it('marca los tipos cuya categoría está inactiva (US2 escenario 4)', async () => {
    render(<AssetTypesTab />)

    const motosierra = await screen.findByRole('row', { name: /Motosierra/ })
    expect(within(motosierra).getByText('Categoría inactiva')).toBeInTheDocument()
    expect(within(motosierra).getByText('Activo')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Casco estructural/ })).queryByText('Categoría inactiva')).not.toBeInTheDocument()
  })

  it('filtra por categoría y por modo de control', async () => {
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Motosierra/ })

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por categoría' }), 'EPP')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ assetCategoryId: 1, page: 1 })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por modo de control' }), 'Por cantidad')
    await waitFor(() =>
      expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ assetCategoryId: 1, trackingMode: 'quantity' }))
    )
  })

  it('al crear solo ofrece categorías activas y exige el modo (US2 escenarios 3 y 6)', async () => {
    create.mockResolvedValue({ data: assetType({ id: 9, name: 'Radio portátil' }) })
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Motosierra/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo tipo de activo' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo tipo de activo' })

    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Categoría' }))
    expect(await screen.findByRole('option', { name: 'Comunicaciones' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Herramientas/ })).not.toBeInTheDocument()
    await act(async () => {
      const option = screen.getByRole('option', { name: 'Comunicaciones' })
      fireEvent.pointerDown(option)
      fireEvent.click(option)
    })

    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Radio portátil')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    expect(await within(dialog).findByText('Selecciona el modo de control.')).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Modo de control' }), 'Serializado')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(create).toHaveBeenCalledWith({
      name: 'Radio portátil',
      description: null,
      asset_category_id: 2,
      tracking_mode: 'serialized',
      min_stock: null,
    })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Tipo de activo "Radio portátil" creado.'))
  })

  it('al editar conserva la categoría actual aunque esté inactiva, marcada (edge case)', async () => {
    update.mockResolvedValue({ data: types[2] })
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Motosierra/ })

    await userEvent.click(screen.getByRole('button', { name: 'Editar Motosierra' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar tipo de activo' })
    expect(within(dialog).getByRole('combobox', { name: 'Categoría' })).toHaveTextContent('Herramientas (inactiva)')

    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Categoría' }))
    expect(await screen.findByRole('option', { name: 'Herramientas (inactiva)' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'EPP' })).toBeInTheDocument()
    fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' })

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Modo de control' }), 'Por cantidad')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(update).toHaveBeenCalledWith(3, {
      name: 'Motosierra',
      description: null,
      asset_category_id: 3,
      tracking_mode: 'quantity',
      min_stock: null,
    })
  })

  it('"Stock mínimo" solo aparece con el modo "Por cantidad" y se envía (RN-028, 005 D-008)', async () => {
    create.mockResolvedValue({ data: assetType({ id: 9, name: 'Guantes', tracking_mode: 'quantity' }) })
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Motosierra/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo tipo de activo' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo tipo de activo' })
    expect(within(dialog).queryByLabelText(/Stock mínimo/)).not.toBeInTheDocument()

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Categoría' }), 'EPP')
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Modo de control' }), 'Por cantidad')
    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Guantes')
    const minStock = within(dialog).getByLabelText(/Stock mínimo/)
    await userEvent.type(minStock, '-2')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    expect(await within(dialog).findByText('El stock mínimo debe ser un número entero mayor o igual a 0.')).toBeInTheDocument()
    expect(create).not.toHaveBeenCalled()

    await userEvent.clear(minStock)
    await userEvent.type(minStock, '12')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    expect(create).toHaveBeenCalledWith({
      name: 'Guantes',
      description: null,
      asset_category_id: 1,
      tracking_mode: 'quantity',
      min_stock: 12,
    })
  })

  it('al editar un tipo por cantidad muestra su stock mínimo y lo oculta al pasar a serializado', async () => {
    list.mockResolvedValue(page([assetType({ id: 2, name: 'Guantes de rescate', tracking_mode: 'quantity', min_stock: 8 })]))
    update.mockResolvedValue({ data: types[1] })
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Guantes de rescate/ })

    await userEvent.click(screen.getByRole('button', { name: 'Editar Guantes de rescate' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar tipo de activo' })
    expect(within(dialog).getByLabelText(/Stock mínimo/)).toHaveValue(8)

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Modo de control' }), 'Serializado')
    expect(within(dialog).queryByLabelText(/Stock mínimo/)).not.toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    expect(update).toHaveBeenCalledWith(2, expect.objectContaining({ tracking_mode: 'serialized', min_stock: null }))
  })

  it('muestra junto al campo el rechazo del cambio de modo (FR-009)', async () => {
    update.mockRejectedValue(
      new ApiValidationError('Inválido', { tracking_mode: ['No se puede cambiar el modo de control: el tipo ya tiene activos.'] })
    )
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Casco estructural/ })

    await userEvent.click(screen.getByRole('button', { name: 'Editar Casco estructural' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar tipo de activo' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByText(/el tipo ya tiene activos/)).toBeInTheDocument()
  })

  it('sin categorías activas avisa en lugar del formulario', async () => {
    getOptions.mockResolvedValue({ data: { ...options, asset_categories: [] } })
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Motosierra/ })
    await waitFor(() => expect(getOptions).toHaveBeenCalled())

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo tipo de activo' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo tipo de activo' })

    expect(within(dialog).getByText('Primero crea una categoría activa.')).toBeInTheDocument()
    expect(within(dialog).queryByLabelText('Nombre')).not.toBeInTheDocument()
  })

  it('sin "configuracion.gestionar" no muestra acciones (FR-011)', async () => {
    can.mockReturnValue(false)
    render(<AssetTypesTab />)
    await screen.findByRole('row', { name: /Motosierra/ })

    expect(screen.queryByRole('button', { name: 'Nuevo tipo de activo' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Editar|Desactivar|Eliminar/ })).not.toBeInTheDocument()
  })
})
