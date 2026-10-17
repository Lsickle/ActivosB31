'use client'

import { useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { AssetTypesTab } from './AssetTypesTab'
import { CategoriesTab } from './CategoriesTab'
import { CompaniesTab } from './CompaniesTab'
import { LocationTypesTab } from './LocationTypesTab'
import { LocationsTab } from './LocationsTab'
import { PositionsTab } from './PositionsTab'
import { SettingsTab } from './SettingsTab'

// Módulo Configuración (spec 002): los catálogos base en pestañas, más cargos
// y compañías del personal (spec 004, US2) y los ajustes globales (spec 005, D-005).
// Ver = configuracion.ver; las acciones de cada pestaña exigen
// configuracion.gestionar (RN-011). Las pestañas ocultas se desmontan: al
// volver a una se recargan sus datos y opciones (p. ej. una categoría recién
// creada aparece en el formulario de tipos de activo).
export function ConfiguracionScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('configuracion.ver')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">Configuración</h1>
        <p className="text-sm text-muted-foreground">Categorías y tipos de activo, tipos de ubicación, ubicaciones, cargos, compañías y ajustes.</p>
      </div>
      <Tabs defaultValue="categorias">
        <TabsList className="max-w-full overflow-x-auto">
          <TabsTrigger value="categorias">Categorías</TabsTrigger>
          <TabsTrigger value="tipos-activo">Tipos de activo</TabsTrigger>
          <TabsTrigger value="tipos-ubicacion">Tipos de ubicación</TabsTrigger>
          <TabsTrigger value="ubicaciones">Ubicaciones</TabsTrigger>
          <TabsTrigger value="cargos">Cargos</TabsTrigger>
          <TabsTrigger value="companias">Compañías</TabsTrigger>
          <TabsTrigger value="ajustes">Ajustes</TabsTrigger>
        </TabsList>
        <TabsContent value="categorias" className="pt-2">
          <CategoriesTab />
        </TabsContent>
        <TabsContent value="tipos-activo" className="pt-2">
          <AssetTypesTab />
        </TabsContent>
        <TabsContent value="tipos-ubicacion" className="pt-2">
          <LocationTypesTab />
        </TabsContent>
        <TabsContent value="ubicaciones" className="pt-2">
          <LocationsTab />
        </TabsContent>
        <TabsContent value="cargos" className="pt-2">
          <PositionsTab />
        </TabsContent>
        <TabsContent value="companias" className="pt-2">
          <CompaniesTab />
        </TabsContent>
        {/* 005 D-005: anticipación global de vencimientos (RN-030). */}
        <TabsContent value="ajustes" className="pt-2">
          <SettingsTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}
