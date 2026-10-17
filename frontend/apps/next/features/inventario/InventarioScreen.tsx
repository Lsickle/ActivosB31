'use client'

import { useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useConfigurationOptions } from '@/features/configuracion/useConfigurationOptions'
import { CountsTab } from './CountsTab'
import { MovementsTab } from './MovementsTab'
import { SerializedTab } from './SerializedTab'
import { StockTab } from './StockTab'

// Módulo Inventario (spec 006): ver = inventario.ver; registrar movimientos y
// conteos = inventario.gestionar (FR-006). Las pestañas ocultas se desmontan:
// al volver a una se recargan sus datos (p. ej. existencias tras un movimiento).
export function InventarioScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('inventario.ver')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return <InventarioTabs />
}

function InventarioTabs() {
  const { options, failed } = useConfigurationOptions()

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">Inventario</h1>
        <p className="text-sm text-muted-foreground">Existencias por ubicación, movimientos, conteos físicos y resumen de serializados.</p>
      </div>
      <Tabs defaultValue="existencias">
        <TabsList className="max-w-full overflow-x-auto">
          <TabsTrigger value="existencias">Existencias</TabsTrigger>
          <TabsTrigger value="movimientos">Movimientos</TabsTrigger>
          <TabsTrigger value="conteos">Conteos</TabsTrigger>
          <TabsTrigger value="serializados">Serializados</TabsTrigger>
        </TabsList>
        <TabsContent value="existencias" className="pt-2">
          <StockTab options={options} optionsFailed={failed} />
        </TabsContent>
        <TabsContent value="movimientos" className="pt-2">
          <MovementsTab options={options} optionsFailed={failed} />
        </TabsContent>
        <TabsContent value="conteos" className="pt-2">
          <CountsTab options={options} optionsFailed={failed} />
        </TabsContent>
        <TabsContent value="serializados" className="pt-2">
          <SerializedTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}
