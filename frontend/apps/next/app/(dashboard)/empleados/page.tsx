import { EmpleadosScreen } from '@/features/empleados/EmpleadosScreen'

export const metadata = { title: 'Empleados' }

// spec 004: EmpleadosScreen exige empleados.ver (RN-022) con useRequireAuth.
export default function EmpleadosPage() {
  return <EmpleadosScreen />
}
