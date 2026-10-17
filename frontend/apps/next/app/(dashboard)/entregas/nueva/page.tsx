import { NewDeliveryScreen } from '@/features/entregas/NewDeliveryScreen'

export const metadata = { title: 'Nueva entrega' }

// spec 007 US1: el asistente exige entregas.gestionar (FR-006) con useRequireAuth.
export default function NewDeliveryPage() {
  return <NewDeliveryScreen />
}
