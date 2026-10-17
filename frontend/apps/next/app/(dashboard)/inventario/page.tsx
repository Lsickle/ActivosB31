import { ModulePlaceholder } from '@/components/module-placeholder'

export const metadata = { title: 'Inventario' }

export default function InventarioPage() {
  return <ModulePlaceholder permission="inventario.ver" title="Inventario" description="Existencias por ubicación, movimientos y conteos." />
}
