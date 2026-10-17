import { ActivosScreen } from '@/features/activos/ActivosScreen'

export const metadata = { title: 'Activos' }

// spec 005: ActivosScreen exige activos.ver (FR-007) con useRequireAuth.
export default function ActivosPage() {
  return <ActivosScreen />
}
