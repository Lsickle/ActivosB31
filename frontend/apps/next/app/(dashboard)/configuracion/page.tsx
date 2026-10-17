import { ModulePlaceholder } from '@/components/module-placeholder'

export const metadata = { title: 'Configuración' }

export default function ConfiguracionPage() {
  return <ModulePlaceholder permission="configuracion.ver" title="Configuración" description="Categorías de activos, tipos de activo (por categoría) y ubicaciones." />
}
