import { AssetDetailScreen } from '@/features/activos/AssetDetailScreen'

export const metadata = { title: 'Ficha del activo' }

// spec 005: ficha del activo. AssetDetailScreen lee el id con useParams y
// exige activos.ver (FR-007) con useRequireAuth.
export default function AssetDetailPage() {
  return <AssetDetailScreen />
}
