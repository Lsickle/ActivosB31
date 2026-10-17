import { ChangePasswordForm } from '@/features/auth/ChangePasswordForm'

export const metadata = { title: 'Cambiar contraseña' }

// Fuera del layout (dashboard): con contraseña temporal pendiente (RN-014)
// el usuario no debe ver el menú de módulos.
export default function CambiarContrasenaPage() {
  return (
    <main className="flex min-h-svh flex-1 items-center justify-center bg-muted p-4">
      <ChangePasswordForm />
    </main>
  )
}
