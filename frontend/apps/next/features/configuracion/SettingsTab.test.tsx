import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiValidationError } from 'app/lib/api-client'
import { SettingsTab } from './SettingsTab'

const getSettings = vi.fn()
const updateSettings = vi.fn()
const toastSuccess = vi.fn()
const can = vi.fn()

vi.mock('app/features/activos/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/activos/api')>()),
  settingsApi: {
    get: () => getSettings(),
    update: (...args: unknown[]) => updateSettings(...args),
  },
}))
vi.mock('app/provider/auth', () => ({ useCan: (code: string) => can(code) }))
vi.mock('sonner', () => ({ toast: { success: (...args: unknown[]) => toastSuccess(...args), error: vi.fn() } }))

const LABEL = 'Anticipación de alertas de vencimiento (días)'

describe('SettingsTab (005 D-005, RN-030)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    can.mockReturnValue(true)
    getSettings.mockResolvedValue({ data: { expiry_alert_days: 30 } })
  })

  it('con configuracion.gestionar muestra y guarda la anticipación global de vencimientos', async () => {
    updateSettings.mockResolvedValue({ data: { expiry_alert_days: 45 } })
    render(<SettingsTab />)

    const input = await screen.findByLabelText(LABEL)
    expect(input).toHaveValue(30)
    expect(can).toHaveBeenCalledWith('configuracion.gestionar')

    await userEvent.clear(input)
    await userEvent.type(input, '400')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar ajustes' }))
    expect(await screen.findByText('La anticipación debe ser un número entero de días entre 0 y 365.')).toBeInTheDocument()
    expect(updateSettings).not.toHaveBeenCalled()

    await userEvent.clear(input)
    await userEvent.type(input, '45')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar ajustes' }))

    expect(updateSettings).toHaveBeenCalledWith({ expiry_alert_days: 45 })
    await waitFor(() => expect(toastSuccess).toHaveBeenCalledWith('Ajustes guardados.'))
  })

  it('muestra junto al campo los errores 422 del servidor', async () => {
    updateSettings.mockRejectedValue(new ApiValidationError('Inválido', { expiry_alert_days: ['Valor no permitido.'] }))
    render(<SettingsTab />)

    await screen.findByLabelText(LABEL)
    await userEvent.click(screen.getByRole('button', { name: 'Guardar ajustes' }))

    expect(await screen.findByText('Valor no permitido.')).toBeInTheDocument()
  })

  it('sin configuracion.gestionar solo la muestra, sin poder editar', async () => {
    can.mockReturnValue(false)
    render(<SettingsTab />)

    const input = await screen.findByLabelText(LABEL)
    expect(input).toHaveValue(30)
    expect(input).toHaveAttribute('readonly')
    expect(screen.queryByRole('button', { name: 'Guardar ajustes' })).not.toBeInTheDocument()
  })

  it('avisa si no se pudieron cargar los ajustes', async () => {
    getSettings.mockRejectedValue(new Error('boom'))
    render(<SettingsTab />)

    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar los ajustes.')
  })
})
