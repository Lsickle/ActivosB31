import { ModulePlaceholder } from '@/components/module-placeholder'

export const metadata = { title: 'Entregas' }

export default function EntregasPage() {
  return <ModulePlaceholder permission="entregas.ver" title="Entregas" description="Entregas y devoluciones de activos a empleados." />
}
