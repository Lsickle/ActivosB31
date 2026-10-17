import { LoginForm } from '@/features/auth/LoginForm'

export const metadata = { title: 'Iniciar sesión' }

export default function LoginPage() {
  return (
    <main className="flex min-h-svh flex-1 items-center justify-center bg-muted p-4">
      <LoginForm />
    </main>
  )
}
