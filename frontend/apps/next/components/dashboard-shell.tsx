'use client'

import { AppSidebar } from '@/components/app-sidebar'
import { ThemeToggle } from '@/components/theme-toggle'
import { Separator } from '@/components/ui/separator'
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar'
import { useRequireAuth } from 'app/provider/auth'

// Shell de las pantallas autenticadas (bloque shadcn "dashboard-01"). Vive en
// app/(dashboard)/layout.tsx para que el sidebar persista entre navegaciones.
export function DashboardShell({ children }: { children: React.ReactNode }) {
  const { isAuthorized } = useRequireAuth()

  if (!isAuthorized) {
    return null
  }

  return (
    <SidebarProvider
      style={
        {
          '--sidebar-width': 'calc(var(--spacing) * 64)',
          '--header-height': 'calc(var(--spacing) * 12)',
        } as React.CSSProperties
      }
    >
      <AppSidebar variant="inset" />
      {/* min-w-0: sin él, una tabla ancha (p. ej. la matriz de permisos) estira la página en vez de desplazarse dentro de su contenedor. */}
      <SidebarInset className="min-w-0">
        <header className="flex h-(--header-height) shrink-0 items-center gap-2 border-b">
          <div className="flex w-full items-center gap-1 px-4 lg:gap-2 lg:px-6">
            <SidebarTrigger className="-ml-1" />
            <Separator orientation="vertical" className="mx-2 h-4 data-vertical:self-auto" />
            <span className="truncate text-base font-medium">Estación de Bomberos B31 · Madrid, Cundinamarca</span>
            <ThemeToggle className="ml-auto" />
          </div>
        </header>
        <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:gap-6 md:p-6">{children}</div>
      </SidebarInset>
    </SidebarProvider>
  )
}
