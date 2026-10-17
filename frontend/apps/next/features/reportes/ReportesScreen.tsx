'use client'

import { useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useConfigurationOptions } from '@/features/configuracion/useConfigurationOptions'
import { ArticleReportTab } from './ArticleReportTab'
import { EmployeeReportTab } from './EmployeeReportTab'
import { InventoryReportTab } from './InventoryReportTab'

// Módulo Reportes (spec 008): todo exige reportes.ver (FR-003). Los datos
// personales del reporte por empleado los decide el servidor (RN-022, D-003).
export function ReportesScreen() {
  const { isAuthorized, isForbidden } = useRequireAuth('reportes.ver')

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return <ReportesTabs />
}

function ReportesTabs() {
  const { options, failed } = useConfigurationOptions()

  return (
    <div className="flex flex-col gap-4">
      <div>
        <h1 className="text-xl font-semibold">Reportes</h1>
        <p className="text-sm text-muted-foreground">Inventario general, reporte por empleado y por artículo. Exportables a Excel y PDF.</p>
      </div>
      <Tabs defaultValue="inventario">
        <TabsList className="max-w-full overflow-x-auto">
          <TabsTrigger value="inventario">Inventario general</TabsTrigger>
          <TabsTrigger value="empleado">Por empleado</TabsTrigger>
          <TabsTrigger value="articulo">Por artículo</TabsTrigger>
        </TabsList>
        <TabsContent value="inventario" className="pt-2">
          <InventoryReportTab options={options} optionsFailed={failed} />
        </TabsContent>
        <TabsContent value="empleado" className="pt-2">
          <EmployeeReportTab />
        </TabsContent>
        <TabsContent value="articulo" className="pt-2">
          <ArticleReportTab options={options} optionsFailed={failed} />
        </TabsContent>
      </Tabs>
    </div>
  )
}
