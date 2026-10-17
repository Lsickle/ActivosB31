import { InventarioScreen } from '@/features/inventario/InventarioScreen'

export const metadata = { title: 'Inventario' }

// spec 006: InventarioScreen exige inventario.ver (FR-006) con useRequireAuth.
export default function InventarioPage() {
  return <InventarioScreen />
}
