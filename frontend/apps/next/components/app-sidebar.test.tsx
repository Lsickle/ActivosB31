import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { SidebarProvider } from '@/components/ui/sidebar'
import { navItems, visibleNavItems } from '@/config/sidebar-nav'
import { AppSidebar } from './app-sidebar'

const useAuth = vi.fn()

vi.mock('next/navigation', () => ({ usePathname: () => '/', useRouter: () => ({ push: vi.fn() }) }))
vi.mock('app/provider/auth', () => ({ useAuth: () => useAuth() }))
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }))

describe('navegación por permisos (US5, RN-009)', () => {
  it('cada módulo declara su permiso <modulo>.ver; Inicio no exige permiso', () => {
    const byUrl = Object.fromEntries(navItems.map((item) => [item.url, item.permission]))

    expect(byUrl).toEqual({
      '/': undefined,
      '/activos': 'activos.ver',
      '/inventario': 'inventario.ver',
      '/entregas': 'entregas.ver',
      '/empleados': 'empleados.ver',
      '/reportes': 'reportes.ver',
      '/usuarios': 'usuarios.ver',
      '/configuracion': 'configuracion.ver',
    })
  })

  it('visibleNavItems deja solo los ítems sin permiso o con permiso concedido', () => {
    const titles = visibleNavItems(navItems, ['activos.ver', 'usuarios.gestionar']).map((item) => item.title)

    expect(titles).toEqual(['Inicio', 'Activos'])
  })
})

describe('AppSidebar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function renderSidebar() {
    return render(
      <SidebarProvider>
        <AppSidebar />
      </SidebarProvider>
    )
  }

  it('muestra solo los módulos con "Ver"', () => {
    useAuth.mockReturnValue({
      user: { name: 'Ana', username: 'ana', email: null, permissions: ['activos.ver', 'reportes.ver'] },
      logout: vi.fn(),
    })
    renderSidebar()

    const nav = screen.getAllByRole('list')[1]!
    expect(within(nav).getByRole('link', { name: 'Inicio' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Activos' })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: 'Reportes' })).toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Usuarios y roles' })).not.toBeInTheDocument()
    expect(within(nav).queryByRole('link', { name: 'Configuración' })).not.toBeInTheDocument()
  })

  it('el menú del usuario muestra el usuario si no tiene correo y ofrece cambiar la contraseña (US3)', async () => {
    useAuth.mockReturnValue({
      user: { name: 'Ana Consulta', username: 'ana', email: null, permissions: [] },
      logout: vi.fn(),
    })
    renderSidebar()

    expect(screen.getByText('ana')).toBeInTheDocument()
    await userEvent.click(screen.getByText('Ana Consulta'))
    expect(await screen.findByRole('menuitem', { name: 'Cambiar contraseña' })).toBeInTheDocument()
  })
})
