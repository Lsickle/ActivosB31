import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AssetCategoryRecord } from 'app/features/configuracion/api'
import type { Paginated } from 'app/lib/api-helpers'
import { CategoriesTab } from './CategoriesTab'

const list = vi.fn()
const create = vi.fn()
const update = vi.fn()
const updateStatus = vi.fn()
const toastSuccess = vi.fn()
const toastError = vi.fn()
const can = vi.fn()

vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  assetCategoriesApi: {
    list: (...args: unknown[]) => list(...args),
    create: (...args: unknown[]) => create(...args),
    update: (...args: unknown[]) => update(...args),
    updateStatus: (...args: unknown[]) => updateStatus(...args),
  },
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({
  toast: { success: (...args: unknown[]) => toastSuccess(...args), error: (...args: unknown[]) => toastError(...args) },
}))

function category(overrides: Partial<AssetCategoryRecord>): AssetCategoryRecord {
  return {
    id: 1,
    name: 'Comunicaciones',
    description: null,
    is_active: true,
    asset_types_count: 2,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

function page<T>(data: T[], meta: Partial<Paginated<T>['meta']> = {}): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length, ...meta } }
}

const categories = [
  category({}),
  category({ id: 2, name: 'Equipos de protección personal (EPP)', description: 'Protección del bombero', asset_types_count: 7 }),
  category({ id: 3, name: 'Herramientas', is_active: false, asset_types_count: 0 }),
]

async function chooseOption(combobox: HTMLElement, name: string) {
  fireEvent.click(combobox)
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

describe('CategoriesTab (US1, FR-001)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    list.mockResolvedValue(page(categories))
  })

  it('lista categorías ordenadas por el servidor, con número de tipos y estado', async () => {
    render(<CategoriesTab />)

    const epp = await screen.findByRole('row', { name: /EPP/ })
    expect(within(epp).getByText('Protección del bombero')).toBeInTheDocument()
    expect(within(epp).getByText('7')).toBeInTheDocument()
    expect(within(epp).getByText('Activa')).toBeInTheDocument()
    expect(within(screen.getByRole('row', { name: /Herramientas/ })).getByText('Inactiva')).toBeInTheDocument()
    expect(list).toHaveBeenCalledWith({ search: '', isActive: undefined, page: 1, perPage: 25 })
    expect(can).toHaveBeenCalledWith('configuracion.gestionar')
  })

  it('busca por nombre (US1 escenario 4)', async () => {
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar categorías' }), 'epp')

    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'epp', page: 1 })))
  })

  it('filtra por estado (US1 escenario 3)', async () => {
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por estado' }), 'Inactivos')

    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ isActive: false, page: 1 })))
  })

  it('pagina con el servidor', async () => {
    list.mockResolvedValue(page(categories, { last_page: 3, total: 60 }))
    render(<CategoriesTab />)
    await screen.findByText('Página 1 de 3 · 60 categorías')

    await userEvent.click(screen.getByRole('button', { name: 'Página siguiente' }))

    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })))
  })

  it('muestra un error si el listado falla', async () => {
    list.mockRejectedValue(new Error('red'))
    render(<CategoriesTab />)

    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar las categorías.')
  })

  it('sin "configuracion.gestionar" no muestra acciones (FR-011)', async () => {
    can.mockReturnValue(false)
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    expect(screen.queryByRole('button', { name: 'Nueva categoría' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Editar/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Desactivar|Activar/ })).not.toBeInTheDocument()
  })

  it('no ofrece eliminar (RN-003, US1 escenario 5)', async () => {
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    expect(screen.queryByRole('button', { name: /Eliminar|Borrar/ })).not.toBeInTheDocument()
  })

  it('crea una categoría y recarga (US1 escenario 1)', async () => {
    create.mockResolvedValue({ data: category({ id: 10, name: 'Consumibles' }) })
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nueva categoría' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nueva categoría' })
    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Consumibles')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(create).toHaveBeenCalledWith({ name: 'Consumibles', description: null })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Categoría "Consumibles" creada.'))
    expect(list).toHaveBeenCalledTimes(2)
  })

  it('edita una categoría existente', async () => {
    update.mockResolvedValue({ data: category({ id: 2, name: 'EPP' }) })
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    await userEvent.click(screen.getByRole('button', { name: 'Editar Equipos de protección personal (EPP)' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar categoría' })
    const name = within(dialog).getByLabelText('Nombre')
    await userEvent.clear(name)
    await userEvent.type(name, 'EPP')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(update).toHaveBeenCalledWith(2, { name: 'EPP', description: 'Protección del bombero' })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Categoría "EPP" actualizada.'))
  })

  it('desactiva tras confirmar, sin cascada sobre sus tipos (US1 escenario 3, FR-008)', async () => {
    updateStatus.mockResolvedValue({ data: category({ id: 2, is_active: false }) })
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Equipos de protección personal (EPP)' }))
    const confirm = await screen.findByRole('alertdialog')
    expect(within(confirm).getByText(/tipos de activo se conservan/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))

    expect(updateStatus).toHaveBeenCalledWith(2, false)
    await waitFor(() =>
      expect(toastSuccess).toHaveBeenCalledWith('Categoría "Equipos de protección personal (EPP)" desactivada.')
    )
    expect(list).toHaveBeenCalledTimes(2)
  })

  it('reactiva una categoría inactiva', async () => {
    updateStatus.mockResolvedValue({ data: category({ id: 3, is_active: true }) })
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /Herramientas/ })

    await userEvent.click(screen.getByRole('button', { name: 'Activar Herramientas' }))
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Activar' }))

    expect(updateStatus).toHaveBeenCalledWith(3, true)
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Categoría "Herramientas" activada.'))
  })

  it('informa el error al cambiar el estado', async () => {
    updateStatus.mockRejectedValue(new Error('No tienes permiso para esta acción.'))
    render(<CategoriesTab />)
    await screen.findByRole('row', { name: /EPP/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Comunicaciones' }))
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Desactivar' }))

    await waitFor(() => expect(toastError).toHaveBeenCalledWith('No tienes permiso para esta acción.'))
  })
})
