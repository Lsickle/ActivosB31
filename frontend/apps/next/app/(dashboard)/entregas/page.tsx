import { EntregasScreen } from '@/features/entregas/EntregasScreen'

export const metadata = { title: 'Entregas' }

// spec 007: EntregasScreen exige entregas.ver (FR-006) con useRequireAuth.
export default function EntregasPage() {
  return <EntregasScreen />
}
