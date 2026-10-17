import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ModulePlaceholder } from './module-placeholder'

const useRequireAuth = vi.fn()

vi.mock('app/provider/auth', () => ({ useRequireAuth: (...args: unknown[]) => useRequireAuth(...args) }))

describe('ModulePlaceholder protegido (US5 escenario 2)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('muestra el módulo con el permiso "Ver"', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: true, isForbidden: false })
    render(<ModulePlaceholder title="Activos" description="Registro de activos." permission="activos.ver" />)

    expect(useRequireAuth).toHaveBeenCalledWith('activos.ver')
    expect(screen.getByText('Activos')).toBeInTheDocument()
  })

  it('muestra "Acceso denegado" sin el permiso', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: true })
    render(<ModulePlaceholder title="Activos" description="Registro de activos." permission="activos.ver" />)

    expect(screen.getByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument()
    expect(screen.queryByText('Registro de activos.')).not.toBeInTheDocument()
  })

  it('no muestra nada mientras carga la sesión', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false, isForbidden: false })
    const { container } = render(<ModulePlaceholder title="Activos" description="x" permission="activos.ver" />)

    expect(container).toBeEmptyDOMElement()
  })
})
