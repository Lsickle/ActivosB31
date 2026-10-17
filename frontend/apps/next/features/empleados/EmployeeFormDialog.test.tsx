import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import type { EmployeeRecord } from 'app/features/empleados/api'
import { ApiValidationError } from 'app/lib/api-client'
import { EmployeeFormDialog } from './EmployeeFormDialog'

const create = vi.fn()
const update = vi.fn()
const onSaved = vi.fn()

vi.mock('app/features/empleados/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/empleados/api')>()),
  employeesApi: {
    list: vi.fn(),
    create: (...args: unknown[]) => create(...args),
    update: (...args: unknown[]) => update(...args),
    updateStatus: vi.fn(),
  },
}))

// Solo activos (FR-004): "Teniente" (id 3) y "Turno B" (id 8) están inactivos.
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
    position: { id: 3, name: 'Teniente', is_active: false },
    company: null,
    phone: '3001234567',
    email: null,
    hire_date: '2020-03-15',
    shirt_size: 'M',
    pants_size: null,
    boot_size: '40',
    glove_size: null,
    is_active: true,
    user: null,
    created_at: '2026-10-17T10:00:00Z',
    updated_at: '2026-10-17T10:00:00Z',
    ...overrides,
  }
}

async function chooseOption(combobox: HTMLElement, name: string) {
  fireEvent.click(combobox)
  const option = await screen.findByRole('option', { name })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

function renderDialog(record: EmployeeRecord | null, opts: ConfigurationOptions | null = options) {
  return render(
    <EmployeeFormDialog open onOpenChange={vi.fn()} employee={record} options={opts} optionsFailed={false} onSaved={onSaved} />
  )
}

describe('EmployeeFormDialog (004 US1, FR-002, FR-003, RN-021)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('agrupa los campos en Identificación, Cargo, Contacto y Tallas de dotación', () => {
    renderDialog(null)
    const dialog = screen.getByRole('dialog', { name: 'Nuevo empleado' })

    for (const section of ['Identificación', 'Cargo e ingreso', 'Contacto', 'Tallas de dotación']) {
      expect(within(dialog).getByRole('group', { name: section })).toBeInTheDocument()
    }
    expect(within(dialog).getByRole('combobox', { name: 'Tipo de documento' })).toHaveTextContent('Cédula de ciudadanía')
  })

  it('crea un empleado con los obligatorios, normalizando el documento (US1-1, D-002)', async () => {
    const created = employee({ id: 11, position: { id: 2, name: 'Cabo', is_active: true } })
    create.mockResolvedValue({ data: created })
    renderDialog(null)
    const dialog = screen.getByRole('dialog', { name: 'Nuevo empleado' })

    await userEvent.type(within(dialog).getByLabelText('Número de documento'), '1.234.567')
    await userEvent.type(within(dialog).getByLabelText('Nombres'), 'Ana')
    await userEvent.type(within(dialog).getByLabelText('Apellidos'), 'Pérez')

    // Solo cargos activos (US2-1).
    fireEvent.click(within(dialog).getByRole('combobox', { name: 'Cargo' }))
    expect(await screen.findByRole('option', { name: 'Bombero' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: /Teniente/ })).not.toBeInTheDocument()
    await act(async () => {
      const option = screen.getByRole('option', { name: 'Cabo' })
      fireEvent.pointerDown(option)
      fireEvent.click(option)
    })
    await chooseOption(within(dialog).getByRole('combobox', { name: 'Compañía' }), 'Turno A')
    await userEvent.type(within(dialog).getByLabelText('Talla de camisa'), 'M')

    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear empleado' }))

    expect(create).toHaveBeenCalledWith({
      document_type: 'CC',
      document_number: '1234567',
      first_name: 'Ana',
      last_name: 'Pérez',
      position_id: 2,
      company_id: 7,
      phone: null,
      email: null,
      hire_date: null,
      shirt_size: 'M',
      pants_size: null,
      boot_size: null,
      glove_size: null,
    })
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(created, true))
  })

  it('valida en el cliente antes de enviar', async () => {
    renderDialog(null)
    const dialog = screen.getByRole('dialog', { name: 'Nuevo empleado' })

    await userEvent.type(within(dialog).getByLabelText(/Correo/), 'no-es-correo')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Crear empleado' }))

    expect(within(dialog).getByText('Ingresa el número de documento.')).toBeInTheDocument()
    expect(within(dialog).getByText('Ingresa los nombres.')).toBeInTheDocument()
    expect(within(dialog).getByText('Ingresa los apellidos.')).toBeInTheDocument()
    expect(within(dialog).getByText('Selecciona un cargo.')).toBeInTheDocument()
    expect(within(dialog).getByText('Ingresa un correo válido.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Número de documento')).toHaveAttribute('aria-invalid', 'true')
    expect(create).not.toHaveBeenCalled()
  })

  it('al editar conserva el cargo actual aunque esté inactivo (US2-1) y permite quedar sin compañía', async () => {
    const record = employee()
    update.mockResolvedValue({ data: record })
    renderDialog(record)
    const dialog = screen.getByRole('dialog', { name: 'Editar empleado' })

    expect(within(dialog).getByRole('combobox', { name: 'Cargo' })).toHaveTextContent('Teniente (inactivo)')
    expect(within(dialog).getByRole('combobox', { name: 'Compañía' })).toHaveTextContent('Sin compañía')
    expect(within(dialog).getByLabelText('Número de documento')).toHaveValue('1234567')
    expect(within(dialog).getByLabelText(/Fecha de ingreso/)).toHaveValue('2020-03-15')

    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(update).toHaveBeenCalledWith(10, {
      document_type: 'CC',
      document_number: '1234567',
      first_name: 'Ana',
      last_name: 'Pérez',
      position_id: 3,
      company_id: null,
      phone: '3001234567',
      email: null,
      hire_date: '2020-03-15',
      shirt_size: 'M',
      pants_size: null,
      boot_size: '40',
      glove_size: null,
    })
    await waitFor(() => expect(onSaved).toHaveBeenCalledWith(record, false))
  })

  it('muestra junto al campo el rechazo del servidor por documento duplicado (US1-2)', async () => {
    update.mockRejectedValue(
      new ApiValidationError('Inválido', { document_number: ['Ya existe un empleado con ese tipo y número de documento.'] })
    )
    renderDialog(employee())
    const dialog = screen.getByRole('dialog', { name: 'Editar empleado' })

    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByText('Ya existe un empleado con ese tipo y número de documento.')).toBeInTheDocument()
    expect(onSaved).not.toHaveBeenCalled()
  })

  it('sin cargos activos avisa en lugar del formulario al crear', () => {
    renderDialog(null, { ...options, positions: [] })
    const dialog = screen.getByRole('dialog', { name: 'Nuevo empleado' })

    expect(within(dialog).getByText(/Primero crea un cargo activo/)).toBeInTheDocument()
    expect(within(dialog).queryByLabelText('Nombres')).not.toBeInTheDocument()
  })
})
