import { render, screen, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { DashboardShell } from './dashboard-shell'

const useRequireAuth = vi.fn()

vi.mock('app/provider/auth', () => ({ useRequireAuth: () => useRequireAuth() }))
vi.mock('@/components/app-sidebar', () => ({ AppSidebar: () => null }))
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }))

describe('DashboardShell', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useRequireAuth.mockReturnValue({ isAuthorized: true })
  })

  it('muestra el logo (decorativo) en la barra superior de todas las páginas', () => {
    render(
      <DashboardShell>
        <p>contenido</p>
      </DashboardShell>
    )
    const header = screen.getByRole('banner')
    const logo = header.querySelector('img[data-slot="brand-logo"]')
    expect(logo).toHaveAttribute('src', '/logo-b31.png')
    expect(logo).toHaveAttribute('alt', '')
    expect(logo).toHaveAttribute('aria-hidden', 'true')
    expect(within(header).queryByRole('img')).not.toBeInTheDocument()
    expect(screen.getByText('contenido')).toBeInTheDocument()
  })

  it('sin sesión no pinta nada', () => {
    useRequireAuth.mockReturnValue({ isAuthorized: false })
    const { container } = render(<DashboardShell>x</DashboardShell>)
    expect(container).toBeEmptyDOMElement()
  })
})
