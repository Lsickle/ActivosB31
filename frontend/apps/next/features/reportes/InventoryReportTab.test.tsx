import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError } from 'app/lib/api-client'
import { chooseOption } from '@/features/activos/test-fixtures'
import { options } from '@/features/inventario/test-fixtures'
import { InventoryReportTab } from './InventoryReportTab'
import { inventoryReport } from './test-fixtures'

const inventory = vi.fn()
const download = vi.fn()
const saveFile = vi.fn()
const toastError = vi.fn()

vi.mock('app/features/reportes/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/reportes/api')>()),
  reportsApi: {
    inventory: (...args: unknown[]) => inventory(...args),
    download: (...args: unknown[]) => download(...args),
  },
}))
vi.mock('./download', () => ({ saveFile: (...args: unknown[]) => saveFile(...args) }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: (...args: unknown[]) => toastError(...args) } }))

describe('InventoryReportTab (008 US1, FR-001, FR-002)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    inventory.mockResolvedValue({ data: inventoryReport() })
    download.mockResolvedValue({ blob: new Blob(['x']), filename: 'inventario-2026-10-17.xlsx' })
  })

  it('muestra serializados por estado y artículos por cantidad con totales, y quién y cuándo lo generó', async () => {
    render(<InventoryReportTab options={options} optionsFailed={false} />)

    const serialized = await screen.findByRole('table', { name: 'Activos serializados' })
    const available = within(serialized).getByRole('row', { name: /Disponible/ })
    expect(within(available).getByText('Cilindro SCBA')).toBeInTheDocument()
    expect(within(available).getByText('Bodega principal')).toBeInTheDocument()
    expect(within(available).getByRole('cell', { name: '6' })).toBeInTheDocument()
    expect(within(within(serialized).getByRole('row', { name: /Asignado/ })).getByText('Sin ubicación')).toBeInTheDocument()
    expect(within(within(serialized).getByRole('row', { name: /^Total/ })).getByRole('cell', { name: '8' })).toBeInTheDocument()

    const quantity = screen.getByRole('table', { name: 'Artículos por cantidad' })
    const gloves = within(quantity).getByRole('row', { name: /Guantes de rescate/ })
    expect(within(gloves).getByText('Bajo mínimo')).toBeInTheDocument()
    expect(within(within(quantity).getByRole('row', { name: /^Total/ })).getByRole('cell', { name: '15' })).toBeInTheDocument()

    expect(screen.getByText(/Administrador/)).toBeInTheDocument()
    expect(inventory).toHaveBeenCalledWith({ assetCategoryId: undefined, assetTypeId: undefined, locationId: undefined, status: undefined })
  })

  it('filtra por categoría, tipo, ubicación y estado', async () => {
    render(<InventoryReportTab options={options} optionsFailed={false} />)
    await screen.findByRole('table', { name: 'Activos serializados' })

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por categoría' }), 'EPP')
    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por tipo' }), 'Cilindro SCBA')
    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por ubicación' }), 'Máquina 1')
    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por estado' }), 'Dado de baja')

    await waitFor(() => expect(inventory).toHaveBeenLastCalledWith({ assetCategoryId: 1, assetTypeId: 3, locationId: 8, status: 'retired' }))
  })

  it('descarga Excel y PDF con los mismos filtros de la pantalla (RN-032)', async () => {
    render(<InventoryReportTab options={options} optionsFailed={false} />)
    await screen.findByRole('table', { name: 'Activos serializados' })
    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por ubicación' }), 'Bodega principal')
    await waitFor(() => expect(inventory).toHaveBeenLastCalledWith(expect.objectContaining({ locationId: 4 })))
    await screen.findByRole('table', { name: 'Activos serializados' })

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en Excel' }))
    await waitFor(() =>
      expect(download).toHaveBeenCalledWith(
        { kind: 'inventory', filters: { assetCategoryId: undefined, assetTypeId: undefined, locationId: 4, status: undefined } },
        'xlsx'
      )
    )
    await waitFor(() => expect(saveFile).toHaveBeenCalledWith({ blob: expect.any(Blob), filename: 'inventario-2026-10-17.xlsx' }, expect.stringMatching(/^inventario-\d{4}-\d{2}-\d{2}\.xlsx$/)))

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en PDF' }))
    await waitFor(() => expect(download).toHaveBeenLastCalledWith(expect.objectContaining({ kind: 'inventory' }), 'pdf'))
  })

  it('si la descarga falla avisa con el mensaje del servidor', async () => {
    download.mockRejectedValue(new ApiError('No tienes permiso para esta acción.', 403))
    render(<InventoryReportTab options={options} optionsFailed={false} />)
    await screen.findByRole('table', { name: 'Activos serializados' })

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en PDF' }))

    await waitFor(() => expect(toastError).toHaveBeenCalledWith('No tienes permiso para esta acción.'))
    expect(saveFile).not.toHaveBeenCalled()
  })

  it('muestra mensajes de vacío por sección y un error si no carga', async () => {
    inventory.mockResolvedValueOnce({
      data: inventoryReport({ serialized: [], quantity: [], totals: { serialized: 0, by_status: { available: 0, assigned: 0, maintenance: 0, retired: 0 }, quantity_units: 0, below_min_types: 0 } }),
    })
    const { unmount } = render(<InventoryReportTab options={options} optionsFailed={false} />)
    expect(await screen.findByText('No hay activos serializados que coincidan con los filtros.')).toBeInTheDocument()
    expect(screen.getByText('No hay existencias por cantidad que coincidan con los filtros.')).toBeInTheDocument()
    unmount()

    inventory.mockRejectedValueOnce(new Error('boom'))
    render(<InventoryReportTab options={options} optionsFailed={false} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible generar el reporte.')
    expect(screen.getByRole('button', { name: 'Descargar en Excel' })).toBeDisabled()
  })
})
