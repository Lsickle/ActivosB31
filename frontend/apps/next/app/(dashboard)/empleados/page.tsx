import { ModulePlaceholder } from '@/components/module-placeholder'

export const metadata = { title: 'Empleados' }

export default function EmpleadosPage() {
  return <ModulePlaceholder permission="empleados.ver" title="Empleados" description="Personal de la estación al que se le asignan activos." />
}
