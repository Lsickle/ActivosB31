import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, ApiValidationError } from 'app/lib/api-client'
import { chooseOption, options } from '@/features/activos/test-fixtures'
import { DeliveryDetailScreen } from './DeliveryDetailScreen'
import { delivery, deliveryItem, glovesItem } from './test-fixtures'

const get = vi.fn()
const createReturn = vi.fn()
const getOptions = vi.fn()
const can = vi.fn()
const useRequireAuth = vi.fn()
const toastSuccess = vi.fn()

vi.mock('next/navigation', () => ({ useParams: () => ({ id: '1' }) }))
vi.mock('app/features/entregas/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/entregas/api')>()),
  deliveriesApi: {
    get: (...args: unknown[]) => get(...args),
    createReturn: (...args: unknown[]) => createReturn(...args),
    list: vi.fn(),
    create: vi.fn(),
    alerts: vi.fn(),
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
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

const withReturns = delivery({
  kind: 'prestamo',
  kind_label: 'Préstamo',
  due_on: '2026-10-30',
  notes: 'Para la emergencia del puente',
  signature_url: 'https://cdn.example/firmas/1.png?sig=abc',
  items: [
    deliveryItem(),
    {
      ...glovesItem,
      returned_quantity: 1,
      pending_quantity: 1,
      returns: [
        {
          id: 9,
          quantity: 1,
          condition: 'good',
          condition_label: 'Bueno',
          to_location: { id: 4, name: 'Bodega principal' },
          notes: null,
          received_by: { id: 1, name: 'Administrador' },
          returned_at: '2026-10-18T15:00:00Z',
        },
      ],
    },
  ],
})

async function openReturnDialog() {
  await userEvent.click(await screen.findByRole('button', { name: 'Registrar devolución' }))
  return screen.findByRole('dialog', { name: 'Registrar devolución' })
}

describe('DeliveryDetailScreen (/entregas/[id], 007 US2, US3)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    can.mockReturnValue(true)
    get.mockResolvedValue({ data: withReturns })
    getOptions.mockResolvedValue({ data: options })
    createReturn.mockResolvedValue({ data: delivery({ status: 'closed' }) })
  })

  it('exige entregas.ver y muestra datos, artículos, devoluciones y la firma', async () => {
    render(<DeliveryDetailScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('entregas.ver')
    expect(await screen.findByRole('heading', { name: 'ENT-000001', level: 1 })).toBeInTheDocument()
    expect(get).toHaveBeenCalledWith(1)
    expect(screen.getByRole('link', { name: /Entregas/ })).toHaveAttribute('href', '/entregas')

    const data = screen.getByRole('region', { name: 'Datos de la entrega' })
    expect(within(data).getByText('Ana Pérez · Bombero')).toBeInTheDocument()
    expect(within(data).getByText('Préstamo')).toBeInTheDocument()
    expect(within(data).getByText('30 oct 2026')).toBeInTheDocument()
    expect(within(data).getByText('Bodega principal')).toBeInTheDocument()
    expect(within(data).getByText('Administrador')).toBeInTheDocument()
    expect(within(data).getByText('Para la emergencia del puente')).toBeInTheDocument()

    const items = screen.getByRole('table', { name: 'Artículos entregados' })
    const helmet = within(items).getByRole('row', { name: /B31-0001/ })
    expect(within(helmet).getByText('Cilindro SCBA')).toBeInTheDocument()
    const gloves = within(items).getByRole('row', { name: /Guantes de rescate/ })
    expect(within(gloves).getAllByText('1')).toHaveLength(2)

    const returns = screen.getByRole('table', { name: 'Devoluciones' })
    const row = within(returns).getByRole('row', { name: /Guantes de rescate/ })
    expect(within(row).getByText('Bueno')).toBeInTheDocument()
    expect(within(row).getByText('Bodega principal')).toBeInTheDocument()

    expect(screen.getByRole('img', { name: 'Firma de Ana Pérez' })).toHaveAttribute('src', 'https://cdn.example/firmas/1.png?sig=abc')
    expect(screen.queryByRole('button', { name: /Editar|Eliminar/ })).not.toBeInTheDocument()
  })

  it('sin entregas.ver muestra "Acceso denegado"', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<DeliveryDetailScreen />)
    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(get).not.toHaveBeenCalled()
  })

  it('404 → "No se encontró la entrega"', async () => {
    get.mockRejectedValue(new ApiError('No encontrado', 404))
    render(<DeliveryDetailScreen />)
    expect(await screen.findByRole('alert')).toHaveTextContent('No se encontró la entrega.')
  })

  it('sin entregas.gestionar no ofrece registrar devolución', async () => {
    can.mockImplementation((code: string) => code !== 'entregas.gestionar')
    render(<DeliveryDetailScreen />)
    await screen.findByRole('heading', { name: 'ENT-000001', level: 1 })
    expect(screen.queryByRole('button', { name: 'Registrar devolución' })).not.toBeInTheDocument()
  })

  it('entrega cerrada: no ofrece devolución', async () => {
    get.mockResolvedValue({
      data: delivery({ status: 'closed', items: [deliveryItem({ returned_quantity: 1, pending_quantity: 0 })] }),
    })
    render(<DeliveryDetailScreen />)
    await screen.findByRole('heading', { name: 'ENT-000001', level: 1 })
    expect(screen.getByText('Cerrada')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Registrar devolución' })).not.toBeInTheDocument()
  })

  it('devuelve el casco en buen estado a una ubicación y recarga (US2-1)', async () => {
    render(<DeliveryDetailScreen />)
    const dialog = await openReturnDialog()

    const helmet = within(dialog).getByRole('group', { name: 'Cilindro SCBA · B31-0001' })
    await userEvent.click(within(helmet).getByRole('checkbox', { name: 'Devolver' }))
    await userEvent.click(within(helmet).getByRole('radio', { name: 'Bueno' }))
    expect(within(helmet).getByText('Queda Disponible en la ubicación elegida y sin empleado.')).toBeInTheDocument()
    await chooseOption(within(helmet).getByRole('combobox', { name: 'Ubicación a la que vuelve' }), 'Máquina 1')

    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar devolución' }))

    await waitFor(() =>
      expect(createReturn).toHaveBeenCalledWith(1, { items: [{ delivery_item_id: 1, quantity: 1, condition: 'good', to_location_id: 8 }] })
    )
    expect(toastSuccess).toHaveBeenCalledWith('Devolución registrada.')
    await waitFor(() => expect(get).toHaveBeenCalledTimes(2))
  })

  it('explica los efectos de cada estado (RN-026, RN-038)', async () => {
    render(<DeliveryDetailScreen />)
    const dialog = await openReturnDialog()

    const helmet = within(dialog).getByRole('group', { name: 'Cilindro SCBA · B31-0001' })
    await userEvent.click(within(helmet).getByRole('checkbox', { name: 'Devolver' }))
    await userEvent.click(within(helmet).getByRole('radio', { name: 'Dañado' }))
    expect(within(helmet).getByText('Queda En mantenimiento en la ubicación elegida.')).toBeInTheDocument()
    await userEvent.click(within(helmet).getByRole('radio', { name: 'Perdido' }))
    expect(within(helmet).getByText('Se da de baja con la observación. Es definitivo.')).toBeInTheDocument()
    expect(within(helmet).queryByRole('combobox', { name: 'Ubicación a la que vuelve' })).not.toBeInTheDocument()

    const gloves = within(dialog).getByRole('group', { name: 'Guantes de rescate' })
    fireEvent.change(within(gloves).getByLabelText('Cantidad a devolver'), { target: { value: '1' } })
    await userEvent.click(within(gloves).getByRole('radio', { name: 'Dañado' }))
    expect(within(gloves).getByText('No vuelve al inventario: se registra como pérdida.')).toBeInTheDocument()
    expect(within(gloves).queryByRole('combobox', { name: 'Ubicación a la que vuelve' })).not.toBeInTheDocument()
  })

  it('perdido exige observación; la cantidad no supera lo pendiente', async () => {
    render(<DeliveryDetailScreen />)
    const dialog = await openReturnDialog()

    const helmet = within(dialog).getByRole('group', { name: 'Cilindro SCBA · B31-0001' })
    await userEvent.click(within(helmet).getByRole('checkbox', { name: 'Devolver' }))
    await userEvent.click(within(helmet).getByRole('radio', { name: 'Perdido' }))

    const gloves = within(dialog).getByRole('group', { name: 'Guantes de rescate' })
    expect(within(gloves).getByText('Pendiente: 1')).toBeInTheDocument()
    fireEvent.change(within(gloves).getByLabelText('Cantidad a devolver'), { target: { value: '2' } })
    await userEvent.click(within(gloves).getByRole('radio', { name: 'Bueno' }))

    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar devolución' }))

    expect(within(helmet).getByText('Describe qué pasó con el artículo perdido.')).toBeInTheDocument()
    expect(within(gloves).getByText('Máximo 1 (pendiente).')).toBeInTheDocument()
    expect(createReturn).not.toHaveBeenCalled()
  })

  it('sin artículos marcados no envía', async () => {
    render(<DeliveryDetailScreen />)
    const dialog = await openReturnDialog()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar devolución' }))
    expect(within(dialog).getByText('Indica al menos un artículo a devolver.')).toBeInTheDocument()
    expect(createReturn).not.toHaveBeenCalled()
  })

  it('muestra el 422 del servidor en el renglón correcto', async () => {
    createReturn.mockRejectedValue(new ApiValidationError('Error', { 'items.0.quantity': ['Supera lo pendiente.'] }))
    render(<DeliveryDetailScreen />)
    const dialog = await openReturnDialog()

    // Solo se envía el renglón de guantes: el índice 0 del payload es ese renglón.
    const gloves = within(dialog).getByRole('group', { name: 'Guantes de rescate' })
    fireEvent.change(within(gloves).getByLabelText('Cantidad a devolver'), { target: { value: '1' } })
    await userEvent.click(within(gloves).getByRole('radio', { name: 'Perdido' }))
    fireEvent.change(within(gloves).getByLabelText('Observación'), { target: { value: 'Se quemaron' } })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar devolución' }))

    expect(await within(gloves).findByText('Supera lo pendiente.')).toBeInTheDocument()
  })
})
