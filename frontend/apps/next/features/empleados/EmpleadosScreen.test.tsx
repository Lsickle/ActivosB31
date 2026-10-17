import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import type { EmployeeRecord } from 'app/features/empleados/api'
import type { Paginated } from 'app/lib/api-helpers'
import { EmpleadosScreen } from './EmpleadosScreen'

const list = vi.fn()
const updateStatus = vi.fn()
const getOptions = vi.fn()
const toastSuccess = vi.fn()
const can = vi.fn()
const useRequireAuth = vi.fn()

vi.mock('app/features/empleados/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/empleados/api')>()),
  employeesApi: {
    list: (...args: unknown[]) => list(...args),
    create: vi.fn(),
    update: vi.fn(),
    updateStatus: (...args: unknown[]) => updateStatus(...args),
  },
}))
vi.mock('app/features/configuracion/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/configuracion/api')>()),
  getConfigurationOptions: () => getOptions(),
}))
// 007 D-007: tenencias del empleado.
const holdings = vi.fn()
vi.mock('app/features/entregas/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/entregas/api')>()),
  employeeHoldings: (...args: unknown[]) => holdings(...args),
}))
vi.mock('app/provider/auth', () => ({
  useCan: (code: string) => can(code),
  useRequireAuth: (...args: unknown[]) => useRequireAuth(...args),
}))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

const options: ConfigurationOptions = {
  asset_categories: [],
  asset_types: [],
  location_types: [],
  locations: [],
  positions: [
    { id: 1, name: 'Bombero' },
    { id: 2, name: 'Cabo' },
  ],
  companies: [{ id: 7, name: 'Turno A' }],
}

function employee(overrides: Partial<EmployeeRecord> = {}): EmployeeRecord {
  return {
    id: 10,
    document_type: 'CC',
    document_number: '1234567',
    first_name: 'Ana',
    last_name: 'Pérez',
    full_name: 'Ana Pérez',
    position: { id: 1, name: 'Bombero', is_active: true },
    company: { id: 7, name: 'Turno A', is_active: true },
    phone: '3001234567',
    email: null,
    hire_date: null,
    shirt_size: null,
    pants_size: null,
    boot_size: null,
    glove_size: null,
    is_active: true,
    user: null,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

function page<T>(data: T[]): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length } }
}

const employees = [
  employee(),
  employee({
    id: 11,
    document_type: 'PA',
    document_number: 'AB1234',
    first_name: 'Luis',
    last_name: 'Gómez',
    full_name: 'Luis Gómez',
    position: { id: 3, name: 'Teniente', is_active: false },
    company: null,
    phone: null,
    is_active: false,
  }),
]

async function chooseOption(combobox: HTMLElement, name: string) {
  fireEvent.click(combobox)
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

describe('EmpleadosScreen (/empleados, 004 US1, US3, RN-022)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    can.mockReturnValue(true)
    list.mockResolvedValue(page(employees))
    getOptions.mockResolvedValue({ data: options })
    holdings.mockResolvedValue({ data: [] })
  })

  it('exige empleados.ver y lista nombre, documento, cargo, compañía, teléfono y estado (FR-001)', async () => {
    render(<EmpleadosScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('empleados.ver')
    expect(screen.getByRole('heading', { name: 'Empleados' })).toBeInTheDocument()

    const ana = await screen.findByRole('row', { name: /Ana Pérez/ })
    expect(within(ana).getByText('CC 1234567')).toBeInTheDocument()
    expect(within(ana).getByText('Bombero')).toBeInTheDocument()
    expect(within(ana).getByText('Turno A')).toBeInTheDocument()
    expect(within(ana).getByText('3001234567')).toBeInTheDocument()
    expect(within(ana).getByText('Activo')).toBeInTheDocument()

    const luis = screen.getByRole('row', { name: /Luis Gómez/ })
    expect(within(luis).getByText('PA AB1234')).toBeInTheDocument()
    expect(within(luis).getByText('Cargo inactivo')).toBeInTheDocument()
    expect(within(luis).getByText('Inactivo')).toBeInTheDocument()

    for (const header of ['Nombre completo', 'Documento', 'Cargo', 'Compañía', 'Teléfono', 'Estado']) {
      expect(screen.getByRole('columnheader', { name: header })).toBeInTheDocument()
    }
    expect(list).toHaveBeenCalledWith({ search: '', positionId: undefined, companyId: undefined, isActive: undefined, page: 1, perPage: 25 })
  })

  it('sin empleados.ver muestra "Acceso denegado" y no consulta (US3-1)', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<EmpleadosScreen />)

    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(list).not.toHaveBeenCalled()
    expect(getOptions).not.toHaveBeenCalled()
  })

  it('busca por nombre o documento (US1-3)', async () => {
    render(<EmpleadosScreen />)
    await screen.findByRole('row', { name: /Ana Pérez/ })

    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar empleados' }), '1234')

    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ search: '1234', page: 1 })))
  })

  it('filtra por cargo, compañía y estado (FR-001, US1-4)', async () => {
    render(<EmpleadosScreen />)
    await screen.findByRole('row', { name: /Ana Pérez/ })
    await waitFor(() => expect(getOptions).toHaveBeenCalled())

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por cargo' }), 'Cabo')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ positionId: 2, page: 1 })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por compañía' }), 'Turno A')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ positionId: 2, companyId: 7 })))

    await chooseOption(screen.getByRole('combobox', { name: 'Filtrar por estado' }), 'Inactivos')
    await waitFor(() => expect(list).toHaveBeenLastCalledWith(expect.objectContaining({ isActive: false })))
  })

  it('desactiva tras confirmar y no ofrece eliminar (US1-4, US1-5)', async () => {
    updateStatus.mockResolvedValue({ data: employee({ is_active: false }) })
    render(<EmpleadosScreen />)
    await screen.findByRole('row', { name: /Ana Pérez/ })

    expect(screen.queryByRole('button', { name: /Eliminar/ })).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Ana Pérez' }))
    const confirm = await screen.findByRole('alertdialog')
    expect(within(confirm).getByText(/no se ofrecerá para nuevas entregas/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))

    expect(updateStatus).toHaveBeenCalledWith(10, false)
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Empleado "Ana Pérez" desactivado.'))
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2))
  })

  it('reactiva un empleado inactivo', async () => {
    updateStatus.mockResolvedValue({ data: employees[1] })
    render(<EmpleadosScreen />)
    await screen.findByRole('row', { name: /Luis Gómez/ })

    await userEvent.click(screen.getByRole('button', { name: 'Activar Luis Gómez' }))
    const confirm = await screen.findByRole('alertdialog')
    await userEvent.click(within(confirm).getByRole('button', { name: 'Activar' }))

    expect(updateStatus).toHaveBeenCalledWith(11, true)
  })

  it('abre el formulario para crear y para editar', async () => {
    render(<EmpleadosScreen />)
    await screen.findByRole('row', { name: /Ana Pérez/ })

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo empleado' }))
    expect(await screen.findByRole('dialog', { name: 'Nuevo empleado' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Cancelar' }))
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())

    await userEvent.click(screen.getByRole('button', { name: 'Editar Ana Pérez' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar empleado' })
    expect(within(dialog).getByLabelText('Nombres')).toHaveValue('Ana')
  })

  it('sin empleados.gestionar solo consulta (RN-022)', async () => {
    can.mockImplementation((code: string) => code !== 'empleados.gestionar')
    render(<EmpleadosScreen />)
    await screen.findByRole('row', { name: /Ana Pérez/ })

    expect(can).toHaveBeenCalledWith('empleados.gestionar')
    expect(screen.queryByRole('button', { name: 'Nuevo empleado' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Editar/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Desactivar|Activar/ })).not.toBeInTheDocument()
  })

  it('avisa si no se pudo cargar el listado', async () => {
    list.mockRejectedValue(new Error('fallo'))
    render(<EmpleadosScreen />)

    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar los empleados.')
  })

  it('"Ver tenencias" muestra lo asignado o prestado, también sin empleados.gestionar (007 US3-2, D-007)', async () => {
    can.mockImplementation((code: string) => code !== 'empleados.gestionar')
    holdings.mockResolvedValue({ data: [] })
    render(<EmpleadosScreen />)

    await userEvent.click(await screen.findByRole('button', { name: 'Ver tenencias de Ana Pérez' }))

    expect(await screen.findByRole('dialog', { name: 'Tenencias de Ana Pérez' })).toBeInTheDocument()
    expect(holdings).toHaveBeenCalledWith(10)
    expect(await screen.findByText('No tiene artículos asignados ni prestados.')).toBeInTheDocument()
  })

  it('al desactivar advierte si tiene artículos pendientes; se permite igual (007 edge case)', async () => {
    holdings.mockResolvedValue({
      data: [
        {
          delivery_item_id: 1,
          delivery: { id: 1, code: 'ENT-000001', kind: 'prestamo', kind_label: 'Préstamo', due_on: null, status: 'open', delivered_at: '2026-10-17T15:00:00Z' },
          asset: null,
          asset_type: { id: 5, name: 'Guantes', tracking_mode: 'quantity' },
          quantity: 3,
          pending_quantity: 3,
        },
      ],
    })
    updateStatus.mockResolvedValue({ data: employee({ is_active: false }) })
    render(<EmpleadosScreen />)
    await screen.findByRole('row', { name: /Ana Pérez/ })

    await userEvent.click(screen.getByRole('button', { name: 'Desactivar Ana Pérez' }))
    const confirm = await screen.findByRole('alertdialog')
    expect(await within(confirm).findByText(/Tiene 3 artículos pendientes de devolver/)).toBeInTheDocument()
    await userEvent.click(within(confirm).getByRole('button', { name: 'Desactivar' }))
    expect(updateStatus).toHaveBeenCalledWith(10, false)
  })
})
