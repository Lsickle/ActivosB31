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

// Módulos de ActivosB31 (alcance en docs/modulos.md). Cada ítem declara el
// permiso `<modulo>.ver` que lo muestra en el menú (RN-009, US5); Inicio no
// exige permiso. Es solo UX: el backend valida cada operación.
export type NavItem = {
  title: string
  url: string
  icon: React.ReactNode
  permission?: string
}

export const navItems: NavItem[] = [
  { title: 'Inicio', url: '/', icon: <LayoutDashboardIcon /> },
  { title: 'Activos', url: '/activos', icon: <PackageIcon />, permission: 'activos.ver' },
  { title: 'Inventario', url: '/inventario', icon: <BoxesIcon />, permission: 'inventario.ver' },
  { title: 'Entregas', url: '/entregas', icon: <HandshakeIcon />, permission: 'entregas.ver' },
  { title: 'Empleados', url: '/empleados', icon: <UsersIcon />, permission: 'empleados.ver' },
  { title: 'Reportes', url: '/reportes', icon: <BarChart3Icon />, permission: 'reportes.ver' },
  { title: 'Usuarios y roles', url: '/usuarios', icon: <ShieldCheckIcon />, permission: 'usuarios.ver' },
  { title: 'Configuración', url: '/configuracion', icon: <SettingsIcon />, permission: 'configuracion.ver' },
]

export function visibleNavItems(items: NavItem[], permissions: string[]): NavItem[] {
  return items.filter((item) => !item.permission || permissions.includes(item.permission))
}
