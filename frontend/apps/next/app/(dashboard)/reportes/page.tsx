import { ModulePlaceholder } from '@/components/module-placeholder'

export const metadata = { title: 'Reportes' }

export default function ReportesPage() {
  return <ModulePlaceholder permission="reportes.ver" title="Reportes" description="Reporte por artículo, reporte por empleado e inventario general." />
}
