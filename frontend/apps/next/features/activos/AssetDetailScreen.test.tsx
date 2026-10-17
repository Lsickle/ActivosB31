import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AssetEventRecord } from 'app/features/activos/api'
import { ApiError } from 'app/lib/api-client'
import { AssetDetailScreen } from './AssetDetailScreen'
import { asset, chooseOption, options, page } from './test-fixtures'

const get = vi.fn()
const updateStatus = vi.fn()
const updateLocation = vi.fn()
const listEvents = vi.fn()
const listPlans = vi.fn()
const listRecords = vi.fn()
const getOptions = vi.fn()
const can = vi.fn()
const useRequireAuth = vi.fn()
const toastSuccess = vi.fn()
const toastError = vi.fn()

vi.mock('next/navigation', () => ({ useParams: () => ({ id: '7' }) }))
vi.mock('app/features/activos/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/activos/api')>()),
  assetsApi: {
    get: (...args: unknown[]) => get(...args),
    updateStatus: (...args: unknown[]) => updateStatus(...args),
    updateLocation: (...args: unknown[]) => updateLocation(...args),
    listEvents: (...args: unknown[]) => listEvents(...args),
    update: vi.fn(),
    uploadPhoto: vi.fn(),
    deletePhoto: vi.fn(),
    maintenancePlans: { list: (...args: unknown[]) => listPlans(...args), create: vi.fn(), update: vi.fn() },
    maintenanceRecords: { list: (...args: unknown[]) => listRecords(...args), create: vi.fn() },
  },
}))
vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  getConfigurationOptions: () => getOptions(),
}))
vi.mock('app/provider/auth', () => ({
  useCan: (code: string) => can(code),
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))
vi.mock('sonner', () => ({
  toast: { success: (...args: unknown[]) => toastSuccess(...args), error: (...args: unknown[]) => toastError(...args) },
}))

function event(overrides: Partial<AssetEventRecord>): AssetEventRecord {
  return {
    id: 1,
    event: 'created',
    from_status: null,
    to_status: null,
    from_location: null,
    to_location: null,
    employee: null,
    user: { id: 1, name: 'Administrador' },
    notes: null,
    changes: null,
    created_at: '2026-10-17T15:00:00Z',
    ...overrides,
  }
}

const events = [
  event({ id: 5, event: 'retired', from_status: 'maintenance', to_status: 'retired', notes: 'Daño irreparable' }),
  event({ id: 4, event: 'location_changed', from_location: { id: 4, name: 'Bodega principal' }, to_location: { id: 8, name: 'Máquina 1' } }),
  event({ id: 3, event: 'status_changed', from_status: 'available', to_status: 'maintenance' }),
  event({ id: 2, event: 'updated', changes: { brand: { from: 'MSA', to: 'Dräger' } } }),
  event({ id: 6, event: 'delivered', employee: { id: 10, full_name: 'Ana Pérez' } }),
  event({ id: 1, event: 'created' }),
]

async function actionsCard() {
  return screen.findByRole('region', { name: 'Estado y ubicación' })
}

describe('AssetDetailScreen (/activos/[id], 005 US2, US3, D-001)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    can.mockReturnValue(true)
    get.mockResolvedValue({ data: asset({ photo_url: 'https://cdn.example/foto.jpg', expires_on: '2030-01-01' }) })
    listEvents.mockResolvedValue(page(events))
    listPlans.mockResolvedValue({ data: [] })
    listRecords.mockResolvedValue(page([]))
    getOptions.mockResolvedValue({ data: options })
  })

  it('exige activos.ver y muestra los datos y la foto temporal (US1-6)', async () => {
    render(<AssetDetailScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('activos.ver')
    expect(await screen.findByRole('heading', { name: 'B31-0001', level: 1 })).toBeInTheDocument()
    expect(get).toHaveBeenCalledWith(7)
    expect(screen.getByRole('link', { name: /Activos/ })).toHaveAttribute('href', '/activos')

    const data = screen.getByRole('region', { name: 'Datos del activo' })
    expect(within(data).getByText('Cilindro SCBA')).toBeInTheDocument()
    expect(within(data).getByText('SN-123')).toBeInTheDocument()
    expect(within(data).getByText('10 feb 2024')).toBeInTheDocument()
    expect(within(data).getByText(/1\.250\.000/)).toBeInTheDocument()
    expect(within(data).getByText('120 meses')).toBeInTheDocument()
    expect(within(data).getByText('1 ene 2030')).toBeInTheDocument()
    expect(within(data).getByText('Bodega principal')).toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Foto del activo B31-0001' })).toHaveAttribute('src', 'https://cdn.example/foto.jpg')
  })

  it('Disponible: envía a mantenimiento tras confirmar (US2-1)', async () => {
    updateStatus.mockResolvedValue({ data: asset({ status: 'maintenance' }) })
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    expect(within(card).queryByRole('button', { name: 'Marcar disponible' })).not.toBeInTheDocument()
    await userEvent.click(within(card).getByRole('button', { name: 'Enviar a mantenimiento' }))
    const confirm = await screen.findByRole('alertdialog')
    await userEvent.click(within(confirm).getByRole('button', { name: 'Enviar a mantenimiento' }))

    await waitFor(() => expect(updateStatus).toHaveBeenCalledWith(7, { status: 'maintenance', reason: null }))
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
    expect(listEvents.mock.calls.length).toBeGreaterThanOrEqual(2)
  })

  it('En mantenimiento: ofrece "Marcar disponible"', async () => {
    get.mockResolvedValue({ data: asset({ status: 'maintenance', status_label: 'En mantenimiento' }) })
    updateStatus.mockResolvedValue({ data: asset() })
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    expect(within(card).queryByRole('button', { name: 'Enviar a mantenimiento' })).not.toBeInTheDocument()
    await userEvent.click(within(card).getByRole('button', { name: 'Marcar disponible' }))
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Marcar disponible' }))
    await waitFor(() => expect(updateStatus).toHaveBeenCalledWith(7, { status: 'available', reason: null }))
  })

  it('Asignado: acciones deshabilitadas con la explicación (US2-2)', async () => {
    get.mockResolvedValue({
      data: asset({ status: 'assigned', status_label: 'Asignado', holder: { id: 10, full_name: 'Ana Pérez', position: 'Bombero' } }),
    })
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    for (const name of ['Enviar a mantenimiento', 'Cambiar ubicación', 'Dar de baja']) {
      expect(within(card).getByRole('button', { name })).toBeDisabled()
    }
    expect(within(card).getByText(/asignado a Ana Pérez.*primero debe devolverse en Entregas/)).toBeInTheDocument()
  })

  it('Dado de baja: sin acciones, es definitivo (US2-3)', async () => {
    get.mockResolvedValue({ data: asset({ status: 'retired', status_label: 'Dado de baja' }) })
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    expect(within(card).queryByRole('button')).not.toBeInTheDocument()
    expect(within(card).getByText(/La baja es definitiva/)).toBeInTheDocument()
  })

  it('dar de baja exige motivo y advierte que es definitivo (D-001)', async () => {
    updateStatus.mockResolvedValue({ data: asset({ status: 'retired' }) })
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    await userEvent.click(within(card).getByRole('button', { name: 'Dar de baja' }))
    const dialog = await screen.findByRole('dialog', { name: 'Dar de baja el activo B31-0001' })
    expect(within(dialog).getByText(/definitiva/)).toBeInTheDocument()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Dar de baja' }))
    expect(await within(dialog).findByText('Indica el motivo de la baja.')).toBeInTheDocument()
    expect(updateStatus).not.toHaveBeenCalled()

    await userEvent.type(within(dialog).getByLabelText('Motivo de la baja'), 'Daño irreparable')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Dar de baja' }))
    await waitFor(() => expect(updateStatus).toHaveBeenCalledWith(7, { status: 'retired', reason: 'Daño irreparable' }))
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Activo "B31-0001" dado de baja.'))
  })

  it('muestra el 409 del servidor (p. ej. lo asignaron entretanto)', async () => {
    updateStatus.mockRejectedValue(new ApiError('El activo está asignado: primero debe devolverse.', 409))
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    await userEvent.click(within(card).getByRole('button', { name: 'Enviar a mantenimiento' }))
    await userEvent.click(within(await screen.findByRole('alertdialog')).getByRole('button', { name: 'Enviar a mantenimiento' }))
    await waitFor(() => expect(toastError).toHaveBeenCalledWith('El activo está asignado: primero debe devolverse.'))
  })

  it('cambia la ubicación entre las activas, sin ofrecer la actual', async () => {
    updateLocation.mockResolvedValue({ data: asset({ location: { id: 8, name: 'Máquina 1', is_active: true } }) })
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    await userEvent.click(within(card).getByRole('button', { name: 'Cambiar ubicación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Cambiar ubicación de B31-0001' })
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Nueva ubicación' }), 'Máquina 1')
    expect(screen.queryByRole('option', { name: 'Bodega principal' })).not.toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar ubicación' }))

    await waitFor(() => expect(updateLocation).toHaveBeenCalledWith(7, 8))
  })

  it('avisa si no hay otras ubicaciones activas a las que trasladarlo', async () => {
    getOptions.mockResolvedValue({ data: { ...options, locations: [{ id: 4, name: 'Bodega principal', location_type_id: 1 }] } })
    render(<AssetDetailScreen />)
    const card = await actionsCard()

    await userEvent.click(within(card).getByRole('button', { name: 'Cambiar ubicación' }))
    const dialog = await screen.findByRole('dialog', { name: 'Cambiar ubicación de B31-0001' })
    expect(await within(dialog).findByText(/No hay otras ubicaciones activas/)).toBeInTheDocument()
  })

  it('sin activos.gestionar no muestra acciones ni "Editar" (FR-007)', async () => {
    can.mockImplementation((code: string) => code !== 'activos.gestionar')
    render(<AssetDetailScreen />)
    await screen.findByRole('heading', { name: 'B31-0001', level: 1 })

    expect(screen.queryByRole('region', { name: 'Estado y ubicación' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument()
  })

  it('"Editar" abre el formulario con los datos', async () => {
    render(<AssetDetailScreen />)
    await userEvent.click(await screen.findByRole('button', { name: 'Editar' }))
    expect(await screen.findByRole('dialog', { name: 'Editar activo B31-0001' })).toBeInTheDocument()
  })

  it('historial legible y paginado (US3, D-002)', async () => {
    listEvents.mockResolvedValue(page(events, { last_page: 2, total: 30 }))
    render(<AssetDetailScreen />)

    const history = await screen.findByRole('region', { name: 'Historial' })
    expect(await within(history).findByText('Dado de baja')).toBeInTheDocument()
    expect(within(history).getByText('Motivo: Daño irreparable')).toBeInTheDocument()
    expect(within(history).getByText('Bodega principal → Máquina 1')).toBeInTheDocument()
    expect(within(history).getByText('Disponible → En mantenimiento')).toBeInTheDocument()
    expect(within(history).getByText('Marca: MSA → Dräger')).toBeInTheDocument()
    expect(within(history).getByText('Entregado a Ana Pérez')).toBeInTheDocument()
    expect(within(history).getByText('Activo registrado')).toBeInTheDocument()
    expect(within(history).getAllByText(/Administrador/).length).toBeGreaterThan(0)
    expect(listEvents).toHaveBeenCalledWith(7, { page: 1 })

    await userEvent.click(within(history).getByRole('button', { name: 'Página siguiente' }))
    await waitFor(() => expect(listEvents).toHaveBeenLastCalledWith(7, { page: 2 }))
  })

  it('activo inexistente: lo indica con enlace al listado', async () => {
    get.mockRejectedValue(new ApiError('No encontrado.', 404))
    render(<AssetDetailScreen />)

    expect(await screen.findByText('No se encontró el activo.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Volver a Activos/ })).toHaveAttribute('href', '/activos')
  })

  it('sin activos.ver muestra "Acceso denegado"', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<AssetDetailScreen />)
    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(get).not.toHaveBeenCalled()
  })
})
