import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { MaintenanceRecordRecord } from 'app/features/activos/api'
import { ApiValidationError } from 'app/lib/api-client'
import { MaintenanceSection } from './MaintenanceSection'
import { chooseOption, page, plan } from './test-fixtures'

const listPlans = vi.fn()
const createPlan = vi.fn()
const updatePlan = vi.fn()
const listRecords = vi.fn()
const createRecord = vi.fn()
const toastSuccess = vi.fn()

vi.mock('app/features/activos/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/activos/api')>()),
  assetsApi: {
    maintenancePlans: {
      list: (...args: unknown[]) => listPlans(...args),
      create: (...args: unknown[]) => createPlan(...args),
      update: (...args: unknown[]) => updatePlan(...args),
    },
    maintenanceRecords: {
      list: (...args: unknown[]) => listRecords(...args),
      create: (...args: unknown[]) => createRecord(...args),
    },
  },
}))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

const record: MaintenanceRecordRecord = {
  id: 1,
  plan: { id: 3, name: 'Prueba hidrostática' },
  performed_on: '2022-03-10',
  description: 'Prueba aprobada',
  performed_by: 'Taller externo',
  cost: '150000.00',
  user: { id: 1, name: 'Administrador' },
  created_at: '2022-03-10T15:00:00Z',
}

const plans = [plan(), plan({ id: 4, name: 'Revisión general', interval_months: 12, last_done_on: null, next_due_on: '2020-01-01', is_active: false })]

const onChanged = vi.fn()

async function findPlanRow(name: RegExp) {
  const region = await screen.findByRole('region', { name: 'Planes de mantenimiento' })
  return within(region).findByRole('row', { name })
}

function renderSection(canManage = true) {
  return render(<MaintenanceSection assetId={7} canManage={canManage} onChanged={onChanged} />)
}

describe('MaintenanceSection (005 US4, RN-029, RN-030, D-003)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    listPlans.mockResolvedValue({ data: plans })
    listRecords.mockResolvedValue(page([record]))
  })

  it('lista los planes con periodicidad, último, próximo (calculado), anticipación y estado', async () => {
    renderSection()

    const row = await findPlanRow(/Prueba hidrostática/)
    expect(within(row).getByText('Cada 60 meses')).toBeInTheDocument()
    expect(within(row).getByText('10 mar 2022')).toBeInTheDocument()
    expect(within(row).getByText('10 mar 2027')).toBeInTheDocument()
    expect(within(row).getByText('30 días')).toBeInTheDocument()
    expect(within(row).getByText('Activo')).toBeInTheDocument()

    const inactive = await findPlanRow(/Revisión general/)
    expect(within(inactive).getByText('Inactivo')).toBeInTheDocument()
    expect(listPlans).toHaveBeenCalledWith(7)

    const records = screen.getByRole('region', { name: 'Mantenimientos realizados' })
    const done = await within(records).findByRole('row', { name: /Prueba aprobada/ })
    expect(within(done).getByText('Taller externo')).toBeInTheDocument()
    expect(within(done).getByText(/150\.000/)).toBeInTheDocument()
    expect(listRecords).toHaveBeenCalledWith(7, { page: 1 })
  })

  it('crea un plan con anticipación de 30 días por defecto (RN-030)', async () => {
    createPlan.mockResolvedValue({ data: plan({ id: 9, name: 'Revisión anual' }) })
    renderSection()
    await findPlanRow(/Prueba hidrostática/)

    await userEvent.click(screen.getByRole('button', { name: 'Nuevo plan' }))
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo plan de mantenimiento' })
    expect(within(dialog).getByLabelText(/Anticipación de la alerta/)).toHaveValue(30)

    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    expect(await within(dialog).findByText('Ingresa el nombre del mantenimiento.')).toBeInTheDocument()

    await userEvent.type(within(dialog).getByLabelText('Nombre'), 'Revisión anual')
    await userEvent.type(within(dialog).getByLabelText(/Periodicidad/), '12')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    await waitFor(() =>
      expect(createPlan).toHaveBeenCalledWith(7, {
        name: 'Revisión anual',
        interval_months: 12,
        last_done_on: null,
        alert_days: 30,
        is_active: true,
      })
    )
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Plan "Revisión anual" creado.'))
    expect(onChanged).toHaveBeenCalled()
  })

  it('edita un plan conservando su estado y muestra los 422 junto al campo', async () => {
    updatePlan.mockRejectedValueOnce(new ApiValidationError('Inválido', { interval_months: ['Periodicidad inválida.'] }))
    renderSection()
    await findPlanRow(/Prueba hidrostática/)

    await userEvent.click(screen.getByRole('button', { name: 'Editar Prueba hidrostática' }))
    const dialog = await screen.findByRole('dialog', { name: 'Editar plan de mantenimiento' })
    expect(within(dialog).getByLabelText('Nombre')).toHaveValue('Prueba hidrostática')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))

    expect(await within(dialog).findByText('Periodicidad inválida.')).toBeInTheDocument()
    expect(updatePlan).toHaveBeenCalledWith(7, 3, {
      name: 'Prueba hidrostática',
      interval_months: 60,
      last_done_on: '2022-03-10',
      alert_days: 30,
      is_active: true,
    })
  })

  it('activa un plan inactivo', async () => {
    updatePlan.mockResolvedValue({ data: plans[1] })
    renderSection()
    await findPlanRow(/Revisión general/)

    await userEvent.click(screen.getByRole('button', { name: 'Activar Revisión general' }))
    await waitFor(() =>
      expect(updatePlan).toHaveBeenCalledWith(7, 4, {
        name: 'Revisión general',
        interval_months: 12,
        last_done_on: null,
        alert_days: 30,
        is_active: true,
      })
    )
  })

  it('registra un mantenimiento realizado asociado a un plan activo (US4-2)', async () => {
    createRecord.mockResolvedValue({ data: record })
    renderSection()
    await findPlanRow(/Prueba hidrostática/)

    await userEvent.click(screen.getByRole('button', { name: 'Registrar mantenimiento' }))
    const dialog = await screen.findByRole('dialog', { name: 'Registrar mantenimiento' })

    await chooseOption(within(dialog).getByRole('combobox', { name: 'Plan' }), 'Prueba hidrostática')
    // Los planes inactivos no se ofrecen.
    expect(screen.queryByRole('option', { name: 'Revisión general' })).not.toBeInTheDocument()

    const date = within(dialog).getByLabelText('Fecha de realización')
    await userEvent.clear(date)
    await userEvent.type(date, '2026-10-01')
    await userEvent.type(within(dialog).getByLabelText('Descripción'), 'Prueba aprobada')
    await userEvent.type(within(dialog).getByLabelText(/Costo/), '150000')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar' }))

    await waitFor(() =>
      expect(createRecord).toHaveBeenCalledWith(7, {
        plan_id: 3,
        performed_on: '2026-10-01',
        description: 'Prueba aprobada',
        performed_by: null,
        cost: 150000,
      })
    )
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Mantenimiento registrado.'))
    expect(onChanged).toHaveBeenCalled()
    expect(listPlans).toHaveBeenCalledTimes(2)
  })

  it('el registro exige descripción', async () => {
    renderSection()
    await findPlanRow(/Prueba hidrostática/)

    await userEvent.click(screen.getByRole('button', { name: 'Registrar mantenimiento' }))
    const dialog = await screen.findByRole('dialog', { name: 'Registrar mantenimiento' })
    await userEvent.click(within(dialog).getByRole('button', { name: 'Registrar' }))

    expect(await within(dialog).findByText('Describe el mantenimiento realizado.')).toBeInTheDocument()
    expect(createRecord).not.toHaveBeenCalled()
  })

  it('sin activos.gestionar solo consulta', async () => {
    renderSection(false)
    await findPlanRow(/Prueba hidrostática/)

    expect(screen.queryByRole('button', { name: 'Nuevo plan' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Registrar mantenimiento' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Editar/ })).not.toBeInTheDocument()
  })
})
