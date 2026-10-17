import { render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { EmployeeHoldingsDialog } from './EmployeeHoldingsDialog'
import { holding } from './test-fixtures'

const holdings = vi.fn()
const can = vi.fn()

vi.mock('app/features/entregas/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/entregas/api')>()),
  employeeHoldings: (...args: unknown[]) => holdings(...args),
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))

const employee = { id: 10, full_name: 'Ana Pérez' }

describe('EmployeeHoldingsDialog (007 US3-2, D-007)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    holdings.mockResolvedValue({
      data: [
        holding(),
        holding({
          delivery_item_id: 2,
          delivery: {
            id: 2,
            code: 'ENT-000002',
            kind: 'prestamo',
            kind_label: 'Préstamo',
            due_on: '2026-10-10',
            status: 'overdue',
            delivered_at: '2026-10-01T15:00:00Z',
          },
          asset: null,
          asset_type: { id: 5, name: 'Guantes de rescate', tracking_mode: 'quantity' },
          quantity: 2,
          pending_quantity: 1,
        }),
      ],
    })
  })

  it('lista lo asignado y prestado con su entrega y estado', async () => {
    render(<EmployeeHoldingsDialog employee={employee} onOpenChange={vi.fn()} />)

    const dialog = await screen.findByRole('dialog', { name: 'Tenencias de Ana Pérez' })
    expect(holdings).toHaveBeenCalledWith(10)

    const helmet = await within(dialog).findByRole('row', { name: /B31-0001/ })
    expect(within(helmet).getByText('Dotación')).toBeInTheDocument()
    expect(within(helmet).getByRole('link', { name: 'ENT-000001' })).toHaveAttribute('href', '/entregas/1')

    const gloves = within(dialog).getByRole('row', { name: /Guantes de rescate/ })
    expect(within(gloves).getByText('Préstamo')).toBeInTheDocument()
    expect(within(gloves).getByText('1 de 2')).toBeInTheDocument()
    expect(within(gloves).getByText('Vencida')).toBeInTheDocument()
  })

  it('sin entregas.ver muestra el código sin enlace', async () => {
    can.mockReturnValue(false)
    render(<EmployeeHoldingsDialog employee={employee} onOpenChange={vi.fn()} />)
    const dialog = await screen.findByRole('dialog')
    await within(dialog).findByText('ENT-000001')
    expect(within(dialog).queryByRole('link', { name: 'ENT-000001' })).not.toBeInTheDocument()
  })

  it('sin tenencias lo indica', async () => {
    holdings.mockResolvedValue({ data: [] })
    render(<EmployeeHoldingsDialog employee={employee} onOpenChange={vi.fn()} />)
    expect(await screen.findByText('No tiene artículos asignados ni prestados.')).toBeInTheDocument()
  })

  it('informa si no se pudieron cargar', async () => {
    holdings.mockRejectedValue(new Error('boom'))
    render(<EmployeeHoldingsDialog employee={employee} onOpenChange={vi.fn()} />)
    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar las tenencias.')
  })

  it('cerrado no consulta', () => {
    render(<EmployeeHoldingsDialog employee={null} onOpenChange={vi.fn()} />)
    expect(holdings).not.toHaveBeenCalled()
  })
})
