import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ConfiguracionScreen } from './ConfiguracionScreen'

const useRequireAuth = vi.fn()

vi.mock('app/provider/auth', () => ({ useRequireAuth: (...args: unknown[]) => useRequireAuth(...args) }))
vi.mock('./CategoriesTab', () => ({ CategoriesTab: () => <p>contenido categorías</p> }))
vi.mock('./AssetTypesTab', () => ({ AssetTypesTab: () => <p>contenido tipos de activo</p> }))
vi.mock('./LocationTypesTab', () => ({ LocationTypesTab: () => <p>contenido tipos de ubicación</p> }))
vi.mock('./LocationsTab', () => ({ LocationsTab: () => <p>contenido ubicaciones</p> }))
vi.mock('./PositionsTab', () => ({ PositionsTab: () => <p>contenido cargos</p> }))
vi.mock('./CompaniesTab', () => ({ CompaniesTab: () => <p>contenido compañías</p> }))
vi.mock('./SettingsTab', () => ({ SettingsTab: () => <p>contenido ajustes</p> }))

describe('ConfiguracionScreen (/configuracion, RN-011)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('exige configuracion.ver y muestra las pestañas, Categorías por defecto', async () => {
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    render(<ConfiguracionScreen />)

    expect(useRequireAuth).toHaveBeenCalledWith('configuracion.ver')
    expect(screen.getByRole('heading', { name: 'Configuración' })).toBeInTheDocument()
    expect(screen.getByText('contenido categorías')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Tipos de activo' }))
    expect(await screen.findByText('contenido tipos de activo')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Tipos de ubicación' }))
    expect(await screen.findByText('contenido tipos de ubicación')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Ubicaciones' }))
    expect(await screen.findByText('contenido ubicaciones')).toBeInTheDocument()

    // 004 US2: cargos y compañías como catálogos de Configuración.
    await userEvent.click(screen.getByRole('tab', { name: 'Cargos' }))
    expect(await screen.findByText('contenido cargos')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('tab', { name: 'Compañías' }))
    expect(await screen.findByText('contenido compañías')).toBeInTheDocument()

    // 005 D-005: anticipación global de vencimientos.
    await userEvent.click(screen.getByRole('tab', { name: 'Ajustes' }))
    expect(await screen.findByText('contenido ajustes')).toBeInTheDocument()
  })

  it('sin configuracion.ver muestra "Acceso denegado"', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<ConfiguracionScreen />)

    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(screen.queryByRole('tab')).not.toBeInTheDocument()
  })

  it('mientras carga la sesión no muestra nada', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: false })
    const { container } = render(<ConfiguracionScreen />)

    expect(container).toBeEmptyDOMElement()
  })
})
