import { DeliveryDetailScreen } from '@/features/entregas/DeliveryDetailScreen'

export const metadata = { title: 'Ficha de la entrega' }

// spec 007: ficha de la entrega. DeliveryDetailScreen lee el id con useParams
// y exige entregas.ver (FR-006) con useRequireAuth.
export default function DeliveryDetailPage() {
  return <DeliveryDetailScreen />
}
