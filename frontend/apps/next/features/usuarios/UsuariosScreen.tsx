'use client'

import { useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { PermissionMatrix } from './PermissionMatrix'
import { RolesTab } from './RolesTab'
import { UsersTab } from './UsersTab'

// Módulo "Usuarios y roles" (D-008): pestañas Usuarios, Roles y Permisos.
// Ver = usuarios.ver; las acciones de cada pestaña exigen usuarios.gestionar.
// Las pestañas ocultas se desmontan: al volver a una se recargan sus datos
// (p. ej. un rol recién creado aparece en la matriz).
export function UsuariosScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('usuarios.ver')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">Usuarios y roles</h1>
        <p className="text-sm text-muted-foreground">Cuentas del sistema, roles y la matriz de permisos por módulo.</p>
      </div>
      <Tabs defaultValue="usuarios">
        <TabsList>
          <TabsTrigger value="usuarios">Usuarios</TabsTrigger>
          <TabsTrigger value="roles">Roles</TabsTrigger>
          <TabsTrigger value="permisos">Permisos</TabsTrigger>
        </TabsList>
        <TabsContent value="usuarios" className="pt-2">
          <UsersTab />
        </TabsContent>
        <TabsContent value="roles" className="pt-2">
          <RolesTab />
        </TabsContent>
        <TabsContent value="permisos" className="pt-2">
          <PermissionMatrix />
        </TabsContent>
      </Tabs>
    </div>
  )
}
