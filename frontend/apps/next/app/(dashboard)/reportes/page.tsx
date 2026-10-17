import { ReportesScreen } from '@/features/reportes/ReportesScreen'

export const metadata = { title: 'Reportes' }

// spec 008: ReportesScreen exige reportes.ver (FR-003) con useRequireAuth.
export default function ReportesPage() {
  return <ReportesScreen />
}
