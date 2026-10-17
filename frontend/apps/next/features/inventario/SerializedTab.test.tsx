import { render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SerializedTab } from './SerializedTab'

const summary = vi.fn()

vi.mock('app/features/inventario/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/inventario/api')>()),
  inventoryApi: { serializedSummary: () => summary() },
}))

describe('SerializedTab (006 US2, D-005)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('resume los serializados por tipo, ubicación y estado', async () => {
    summary.mockResolvedValue({
      data: [
        { asset_type: { id: 3, name: 'Cilindro SCBA' }, location: { id: 4, name: 'Bodega principal' }, status: 'available', count: 6 },
        { asset_type: { id: 3, name: 'Cilindro SCBA' }, location: null, status: 'assigned', count: 2 },
      ],
    })
    render(<SerializedTab />)

    const rows = await screen.findAllByRole('row', { name: /Cilindro SCBA/ })
    expect(within(rows[0]!).getByText('Bodega principal')).toBeInTheDocument()
    expect(within(rows[0]!).getByText('Disponible')).toBeInTheDocument()
    expect(within(rows[0]!).getByRole('cell', { name: '6' })).toBeInTheDocument()
    expect(within(rows[1]!).getByText('Sin ubicación')).toBeInTheDocument()
    expect(within(rows[1]!).getByText('Asignado')).toBeInTheDocument()
  })

  it('indica cuando no hay activos serializados', async () => {
    summary.mockResolvedValue({ data: [] })
    render(<SerializedTab />)
    expect(await screen.findByText('No hay activos serializados registrados.')).toBeInTheDocument()
  })
})
