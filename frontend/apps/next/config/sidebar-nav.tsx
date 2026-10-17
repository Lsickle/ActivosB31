import {
  BarChart3Icon,
  BoxesIcon,
  HandshakeIcon,
  LayoutDashboardIcon,
  PackageIcon,
  SettingsIcon,
  ShieldCheckIcon,
  UsersIcon,
} from 'lucide-react'

// Módulos de ActivosB31 (alcance en docs/modulos.md). `permission` queda sin
// definir hasta implementar el módulo de usuarios, roles y permisos; cuando
// exista, cada ítem declara el código que lo habilita.
export type NavItem = {
  title: string
  url: string
  icon: React.ReactNode
  permission?: string
}

export const navItems: NavItem[] = [
  { title: 'Inicio', url: '/', icon: <LayoutDashboardIcon /> },
  { title: 'Activos', url: '/activos', icon: <PackageIcon /> },
  { title: 'Inventario', url: '/inventario', icon: <BoxesIcon /> },
  { title: 'Entregas', url: '/entregas', icon: <HandshakeIcon /> },
  { title: 'Empleados', url: '/empleados', icon: <UsersIcon /> },
  { title: 'Reportes', url: '/reportes', icon: <BarChart3Icon /> },
  { title: 'Usuarios y roles', url: '/usuarios', icon: <ShieldCheckIcon /> },
  { title: 'Configuración', url: '/configuracion', icon: <SettingsIcon /> },
]
