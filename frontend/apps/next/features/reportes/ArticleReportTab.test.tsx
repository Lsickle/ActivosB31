import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { chooseOption } from '@/features/activos/test-fixtures'
import { options } from '@/features/inventario/test-fixtures'
import { ArticleReportTab } from './ArticleReportTab'
import { assetReport, assetTypeReport } from './test-fixtures'

const asset = vi.fn()
const assetType = vi.fn()
const assetOptions = vi.fn()
const assetTypeOptions = vi.fn()
const download = vi.fn()

vi.mock('app/features/reportes/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/reportes/api')>()),
  reportsApi: {
    asset: (...args: unknown[]) => asset(...args),
    assetType: (...args: unknown[]) => assetType(...args),
    assetOptions: (...args: unknown[]) => assetOptions(...args),
    assetTypeOptions: (...args: unknown[]) => assetTypeOptions(...args),
    download: (...args: unknown[]) => download(...args),
  },
}))
vi.mock('./download', () => ({ saveFile: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

describe('ArticleReportTab (008 US3, RN-033)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    assetOptions.mockResolvedValue({
      data: [{ id: 7, plate: 'B31-0001', status: 'retired', status_label: 'Dado de baja', asset_type: { id: 3, name: 'Cilindro SCBA' } }],
    })
    assetTypeOptions.mockResolvedValue({
      data: [
        { id: 5, name: 'Guantes de rescate', is_active: true, category: { id: 1, name: 'EPP' } },
        { id: 11, name: 'Guantes viejos', is_active: false, category: { id: 1, name: 'EPP' } },
      ],
    })
    asset.mockResolvedValue({ data: assetReport() })
    assetType.mockResolvedValue({ data: assetTypeReport() })
    download.mockResolvedValue({ blob: new Blob(['x']), filename: null })
  })

  it('activo serializado: busca por placa (todos los estados), muestra ficha e historial y descarga con el rango', async () => {
    render(<ArticleReportTab options={options} optionsFailed={false} />)

    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar activo por placa' }), 'B31')
    await waitFor(() => expect(assetOptions).toHaveBeenCalledWith('B31'))
    await userEvent.click(await screen.findByRole('button', { name: 'Ver reporte de B31-0001' }))

    await waitFor(() => expect(asset).toHaveBeenCalledWith(7, { from: undefined, to: undefined }))
    expect(await screen.findByText('En mantenimiento')).toBeInTheDocument()
    expect(screen.getByText('SN-123')).toBeInTheDocument()
    const events = screen.getByRole('table', { name: 'Historial del activo' })
    expect(within(events).getByRole('row', { name: /Mantenimiento realizado/ })).toHaveTextContent('Prueba hidrostática')
    expect(within(events).getByRole('row', { name: /^Total/ })).toHaveTextContent('1 evento')

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-01-01' } })
    await waitFor(() => expect(asset).toHaveBeenLastCalledWith(7, { from: '2026-01-01', to: undefined }))
    await screen.findByRole('table', { name: 'Historial del activo' })

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en Excel' }))
    await waitFor(() => expect(download).toHaveBeenCalledWith({ kind: 'asset', assetId: 7, range: { from: '2026-01-01', to: undefined } }, 'xlsx'))
  })

  it('tipo por cantidad: busca tipos (incluidos inactivos); existencias por ubicación y movimientos con totales', async () => {
    render(<ArticleReportTab options={options} optionsFailed={false} />)

    await chooseOption(screen.getByRole('combobox', { name: 'Clase de artículo' }), 'Tipo por cantidad')
    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar tipo de artículo' }), 'Guan')
    await waitFor(() => expect(assetTypeOptions).toHaveBeenCalledWith('Guan'))
    expect(await screen.findByText('Guantes viejos (inactivo)')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Ver reporte de Guantes de rescate' }))

    await waitFor(() => expect(assetType).toHaveBeenCalledWith(5, { from: undefined, to: undefined, locationId: undefined }))
    const stock = await screen.findByRole('table', { name: 'Existencias por ubicación' })
    expect(within(within(stock).getByRole('row', { name: /^Total/ })).getByRole('cell', { name: '15' })).toBeInTheDocument()
    expect(screen.getByText('Bajo mínimo')).toBeInTheDocument()
    const movements = screen.getByRole('table', { name: 'Movimientos' })
    expect(within(movements).getByRole('row', { name: /Entrega/ })).toHaveTextContent('ENT-000022')
    expect(within(movements).getByRole('row', { name: /^Total/ })).toHaveTextContent('2 movimientos')

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por ubicación' }), 'Máquina 1')
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-17' } })
    await waitFor(() => expect(assetType).toHaveBeenLastCalledWith(5, { from: undefined, to: '2026-10-17', locationId: 8 }))
    await screen.findByRole('table', { name: 'Movimientos' })

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en PDF' }))
    await waitFor(() =>
      expect(download).toHaveBeenCalledWith({ kind: 'asset-type', assetTypeId: 5, filters: { from: undefined, to: '2026-10-17', locationId: 8 } }, 'pdf')
    )
  }, 15_000)

  it('sin coincidencias en la búsqueda lo indica', async () => {
    assetOptions.mockResolvedValue({ data: [] })
    render(<ArticleReportTab options={options} optionsFailed={false} />)
    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar activo por placa' }), 'ZZZ')
    expect(await screen.findByText('No hay activos con esa placa o serial.')).toBeInTheDocument()
  })
})
