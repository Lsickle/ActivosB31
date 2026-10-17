import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError } from 'app/lib/api-client'
import { asset, chooseOption, options, page } from '@/features/activos/test-fixtures'
import { NewDeliveryScreen } from './NewDeliveryScreen'
import { delivery } from './test-fixtures'

const create = vi.fn()
const listAssets = vi.fn()
const stock = vi.fn()
const getEmployees = vi.fn()
const getOptions = vi.fn()
const push = vi.fn()
const useRequireAuth = vi.fn()
const toastSuccess = vi.fn()

vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }))
vi.mock('app/features/entregas/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/entregas/api')>()),
  deliveriesApi: { create: (...args: unknown[]) => create(...args), list: vi.fn(), get: vi.fn(), createReturn: vi.fn(), alerts: vi.fn() },
}))
vi.mock('app/features/activos/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/activos/api')>()),
  assetsApi: { list: (...args: unknown[]) => listAssets(...args) },
}))
vi.mock('app/features/inventario/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/inventario/api')>()),
  inventoryApi: { stock: (...args: unknown[]) => stock(...args) },
}))
vi.mock('app/features/empleados/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/empleados/api')>()),
  employeesOptions: () => getEmployees(),
}))
vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  getConfigurationOptions: () => getOptions(),
}))
vi.mock('app/provider/auth', () => ({
  useCan: () => true,
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))
// jsdom no dibuja en canvas: la firma se simula con un botón.
vi.mock('@/components/signature-pad', () => ({
  SignaturePad: ({ label, error, onChange }: { label: string; error?: string; onChange?: (value: string | null) => void }) => (
    <div>
      <span>{label}</span>
      <button type="button" onClick={() => onChange?.('data:image/png;base64,iVBORw0KGgo=')}>
        Simular firma
      </button>
      {error && <p>{error}</p>}
    </div>
  ),
}))

function stockRow(quantity: number) {
  return {
    asset_type: { id: 5, name: 'Guantes de rescate', category: { id: 1, name: 'EPP' } },
    location: { id: 4, name: 'Bodega principal', is_active: true },
    quantity,
    type_total: quantity,
    min_stock: null,
    below_min: false,
  }
}

function next() {
  return userEvent.click(screen.getByRole('button', { name: 'Siguiente' }))
}

function currentStep() {
  return screen.getByRole('list', { name: 'Pasos de la entrega' }).querySelector('[aria-current="step"]')
}

async function chooseEmployee() {
  await userEvent.click(await screen.findByRole('radio', { name: /Ana Pérez/ }))
}

async function goToItems({ kind = 'Dotación' }: { kind?: string } = {}) {
  render(<NewDeliveryScreen />)
  await chooseEmployee()
  await next()
  await userEvent.click(screen.getByRole('radio', { name: new RegExp(kind) }))
  if (kind === 'Préstamo') fireEvent.change(screen.getByLabelText('Fecha de devolución esperada'), { target: { value: '2099-01-31' } })
  await next()
  await chooseOption(await screen.findByRole('combobox', { name: 'Ubicación de origen' }), 'Bodega principal')
  await next()
  await screen.findByRole('heading', { name: 'Artículos' })
}

async function addAsset() {
  fireEvent.change(screen.getByRole('searchbox', { name: 'Buscar activo disponible por placa' }), { target: { value: 'B31' } })
  await userEvent.click(await screen.findByRole('button', { name: 'Agregar B31-0001' }))
}

async function addGloves(quantity: string) {
  await chooseOption(screen.getByRole('combobox', { name: 'Tipo de artículo por cantidad' }), /Guantes de rescate/)
  await screen.findByText('Existencia en Bodega principal: 18')
  fireEvent.change(screen.getByLabelText('Cantidad'), { target: { value: quantity } })
  await userEvent.click(screen.getByRole('button', { name: 'Agregar cantidad' }))
}

describe('NewDeliveryScreen (/entregas/nueva, 007 US1, RN-023..RN-025)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    getOptions.mockResolvedValue({ data: options })
    getEmployees.mockResolvedValue({
      data: [
        { id: 10, full_name: 'Ana Pérez', position: 'Bombero' },
        { id: 11, full_name: 'Luis Gómez', position: 'Cabo' },
      ],
    })
    listAssets.mockResolvedValue(page([asset()]))
    stock.mockResolvedValue(page([stockRow(18)]))
    create.mockResolvedValue({ data: delivery({ id: 1, code: 'ENT-000001' }) })
  })

  it('exige entregas.gestionar', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<NewDeliveryScreen />)
    expect(useRequireAuth).toHaveBeenCalledWith('entregas.gestionar')
    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(getEmployees).not.toHaveBeenCalled()
  })

  it('registra un préstamo firmado con un activo y una cantidad (US1-1)', async () => {
    await goToItems({ kind: 'Préstamo' })

    await addAsset()
    expect(listAssets).toHaveBeenCalledWith({ search: 'B31', status: 'available', perPage: 10 })
    await addGloves('2')
    expect(stock).toHaveBeenCalledWith({ assetTypeId: 5, locationId: 4, perPage: 1 })

    const added = screen.getByRole('table', { name: 'Artículos de la entrega' })
    expect(within(added).getByText('Cilindro SCBA · B31-0001')).toBeInTheDocument()
    expect(within(added).getByText('Guantes de rescate')).toBeInTheDocument()
    await next()

    expect(screen.getByText('Firma de Ana Pérez')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Simular firma' }))
    await next()

    const summary = screen.getByRole('region', { name: 'Resumen' })
    expect(within(summary).getByText('Ana Pérez · Bombero')).toBeInTheDocument()
    expect(within(summary).getByText('Préstamo')).toBeInTheDocument()
    expect(within(summary).getByText('31 ene 2099')).toBeInTheDocument()
    expect(within(summary).getByText('Bodega principal')).toBeInTheDocument()
    expect(within(summary).getByRole('img', { name: 'Firma de Ana Pérez' })).toHaveAttribute('src', 'data:image/png;base64,iVBORw0KGgo=')

    await userEvent.click(screen.getByRole('button', { name: 'Confirmar entrega' }))

    await waitFor(() =>
      expect(create).toHaveBeenCalledWith({
        employee_id: 10,
        kind: 'prestamo',
        due_on: '2099-01-31',
        from_location_id: 4,
        notes: null,
        signature: 'data:image/png;base64,iVBORw0KGgo=',
        items: [{ asset_id: 7 }, { asset_type_id: 5, quantity: 2 }],
      })
    )
    expect(toastSuccess).toHaveBeenCalledWith('Entrega ENT-000001 registrada.')
    expect(push).toHaveBeenCalledWith('/entregas/1')
  })

  it('no avanza sin empleado', async () => {
    render(<NewDeliveryScreen />)
    await screen.findByRole('radio', { name: /Ana Pérez/ })
    await next()
    expect(screen.getByText('Selecciona el empleado.')).toBeInTheDocument()
    expect(currentStep()).toHaveTextContent('Empleado')
  })

  it('filtra empleados por nombre sin tildes', async () => {
    render(<NewDeliveryScreen />)
    await screen.findByRole('radio', { name: /Ana Pérez/ })
    fireEvent.change(screen.getByRole('searchbox', { name: 'Buscar empleado' }), { target: { value: 'perez' } })
    expect(screen.getByRole('radio', { name: /Ana Pérez/ })).toBeInTheDocument()
    expect(screen.queryByRole('radio', { name: /Luis Gómez/ })).not.toBeInTheDocument()
  })

  it('préstamo sin fecha esperada no avanza (US1-2)', async () => {
    render(<NewDeliveryScreen />)
    await chooseEmployee()
    await next()
    await userEvent.click(screen.getByRole('radio', { name: /Préstamo/ }))
    await next()
    expect(screen.getByText('Indica la fecha de devolución esperada.')).toBeInTheDocument()
  })

  it('exige al menos un artículo (RN-025)', async () => {
    await goToItems()
    await next()
    expect(screen.getByText('Agrega al menos un artículo.')).toBeInTheDocument()
  })

  it('no permite pedir más de la existencia del origen (US1-5)', async () => {
    await goToItems()
    await addGloves('19')
    expect(screen.getByText('Solo hay 18 en Bodega principal.')).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Artículos de la entrega' })).not.toBeInTheDocument()
  })

  it('no ofrece dos veces el mismo activo', async () => {
    await goToItems()
    await addAsset()
    expect(screen.getByRole('button', { name: 'B31-0001 ya agregado' })).toBeDisabled()
  })

  it('sin firma no avanza al resumen (RN-024, US1-3)', async () => {
    await goToItems()
    await addAsset()
    await next()
    await next()
    expect(screen.getByText('La firma del empleado es obligatoria.')).toBeInTheDocument()
  })

  it('cambiar los artículos después de firmar borra la firma', async () => {
    await goToItems()
    await addAsset()
    await next()
    await userEvent.click(screen.getByRole('button', { name: 'Simular firma' }))
    await userEvent.click(screen.getByRole('button', { name: /Artículos/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Quitar Cilindro SCBA · B31-0001' }))
    await addGloves('1')
    expect(screen.getByText(/La firma se borró porque cambiaron los datos de la entrega/)).toBeInTheDocument()
  })

  it('un 422 del servidor vuelve al paso con el error (D-003)', async () => {
    create.mockRejectedValue(
      new ApiValidationError('Error', { 'items.0.asset_id': ['El activo B31-0001 ya no está disponible.'] })
    )
    await goToItems()
    await addAsset()
    await next()
    await userEvent.click(screen.getByRole('button', { name: 'Simular firma' }))
    await next()
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar entrega' }))

    expect(await screen.findByText('El activo B31-0001 ya no está disponible.')).toBeInTheDocument()
    expect(currentStep()).toHaveTextContent('Artículos')
    expect(push).not.toHaveBeenCalled()
  })
})
