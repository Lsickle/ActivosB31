import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { EmployeeReportTab } from './EmployeeReportTab'
import { employeeReport } from './test-fixtures'

const employee = vi.fn()
const download = vi.fn()
const employeeOptions = vi.fn()

vi.mock('app/features/reportes/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/reportes/api')>()),
  reportsApi: {
    employee: (...args: unknown[]) => employee(...args),
    employeeOptions: (...args: unknown[]) => employeeOptions(...args),
    download: (...args: unknown[]) => download(...args),
  },
}))
vi.mock('./download', () => ({ saveFile: vi.fn() }))
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }))

async function chooseEmployee() {
  await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar empleado' }), 'Ana')
  await userEvent.click(await screen.findByRole('button', { name: 'Ver reporte de Ana Gómez' }))
}

describe('EmployeeReportTab (008 US2, RN-022, RN-033)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    employeeOptions.mockResolvedValue({
      data: [
        { id: 9, full_name: 'Ana Gómez', position: 'Bombero', is_active: true },
        { id: 10, full_name: 'Ana Ruiz', position: 'Cabo', is_active: false },
      ],
    })
    employee.mockResolvedValue({ data: employeeReport() })
    download.mockResolvedValue({ blob: new Blob(['x']), filename: null })
  })

  it('pide elegir un empleado antes de consultar', async () => {
    render(<EmployeeReportTab />)
    expect(await screen.findByText('Busca un empleado para ver su reporte.')).toBeInTheDocument()
    expect(employee).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Descargar en PDF' })).toBeDisabled()
  })

  it('muestra lo que tiene hoy y el historial del rango, con totales (US2)', async () => {
    render(<EmployeeReportTab />)
    await chooseEmployee()

    await waitFor(() => expect(employee).toHaveBeenCalledWith(9, { from: undefined, to: undefined }))
    const holdings = await screen.findByRole('table', { name: 'Lo que tiene hoy' })
    const loan = within(holdings).getByRole('row', { name: /ENT-000021/ })
    expect(within(loan).getByText('B31-0001')).toBeInTheDocument()
    expect(within(loan).getByText('Vencida')).toBeInTheDocument()
    expect(within(within(holdings).getByRole('row', { name: /^Total/ })).getByRole('cell', { name: '3' })).toBeInTheDocument()

    const history = screen.getByRole('table', { name: 'Historial de entregas y devoluciones' })
    expect(within(within(history).getByRole('row', { name: /Devolución/ })).getByText('Dañado')).toBeInTheDocument()
    expect(within(history).getByRole('row', { name: /^Total/ })).toHaveTextContent('Entregado: 3 · Devuelto: 1')
  })

  it('sin empleados.ver no muestra documento ni contacto (no vienen en la respuesta, RN-022)', async () => {
    render(<EmployeeReportTab />)
    await chooseEmployee()

    expect(await screen.findByText('Bombero')).toBeInTheDocument()
    expect(screen.queryByText(/Documento/)).not.toBeInTheDocument()
    expect(screen.queryByText(/Correo/)).not.toBeInTheDocument()
    expect(screen.getByText(/Los datos personales solo se muestran con el permiso para ver empleados/)).toBeInTheDocument()
  })

  it('con empleados.ver muestra documento y contacto que vienen en la respuesta', async () => {
    employee.mockResolvedValue({
      data: employeeReport({
        employee: {
          id: 9,
          full_name: 'Ana Gómez',
          position: 'Bombero',
          is_active: false,
          document_type: 'CC',
          document_number: '1023456789',
          phone: '3001234567',
          email: 'ana@example.com',
          company: 'Compañía 1',
        },
      }),
    })
    render(<EmployeeReportTab />)
    await chooseEmployee()

    expect(await screen.findByText('CC 1023456789')).toBeInTheDocument()
    expect(screen.getByText('3001234567')).toBeInTheDocument()
    expect(screen.getByText('ana@example.com')).toBeInTheDocument()
    expect(screen.getByText('Compañía 1')).toBeInTheDocument()
    expect(screen.getByText('Inactivo')).toBeInTheDocument()
    expect(screen.queryByText(/Los datos personales solo se muestran/)).not.toBeInTheDocument()
  })

  it('aplica el rango Desde/Hasta y lo valida (RN-033); la descarga usa el mismo rango', async () => {
    render(<EmployeeReportTab />)
    await chooseEmployee()
    await screen.findByRole('table', { name: 'Lo que tiene hoy' })

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-18' } })
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-17' } })
    expect(await screen.findByRole('alert')).toHaveTextContent('"Desde" no puede ser posterior a "Hasta".')
    expect(employee).not.toHaveBeenLastCalledWith(9, { from: '2026-10-18', to: '2026-10-17' })
    expect(screen.getByRole('button', { name: 'Descargar en Excel' })).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } })
    await waitFor(() => expect(employee).toHaveBeenLastCalledWith(9, { from: '2026-10-01', to: '2026-10-17' }))
    await screen.findByRole('table', { name: 'Lo que tiene hoy' })

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en PDF' }))
    await waitFor(() =>
      expect(download).toHaveBeenCalledWith({ kind: 'employee', employeeId: 9, range: { from: '2026-10-01', to: '2026-10-17' } }, 'pdf')
    )
  })

  it('la búsqueda incluye inactivos, marcados "(inactivo)"', async () => {
    render(<EmployeeReportTab />)
    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar empleado' }), 'Ana')

    await waitFor(() => expect(employeeOptions).toHaveBeenCalledWith('Ana'))
    expect(await screen.findByText('Ana Ruiz (inactivo)')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ver reporte de Ana Ruiz' })).toBeInTheDocument()
    expect(screen.queryByText(/Solo se listan empleados activos/)).not.toBeInTheDocument()
  })

  it('avisa si falla la búsqueda de empleados', async () => {
    employeeOptions.mockRejectedValue(new Error('boom'))
    render(<EmployeeReportTab />)
    await userEvent.type(screen.getByRole('searchbox', { name: 'Buscar empleado' }), 'Ana')
    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible buscar empleados.')
  })
})
