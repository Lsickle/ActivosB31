import { InicioScreen } from '@/features/inicio/InicioScreen'

export const metadata = { title: 'Inicio' }

// spec 008 US4 / D-004: tablero con las secciones que el usuario puede ver.
export default function HomePage() {
  return <InicioScreen />
}
