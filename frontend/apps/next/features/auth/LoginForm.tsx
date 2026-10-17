'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { FlameIcon } from 'lucide-react'
import { login } from 'app/features/auth/api'
import { loginSchema } from 'app/features/auth/schemas'
import { ApiValidationError, RateLimitError } from 'app/lib/api-client'
import { CHANGE_PASSWORD_PATH } from 'app/features/auth/paths'
import { useAuth } from 'app/provider/auth'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

export function LoginForm() {
  const router = useRouter()
  const { refresh } = useAuth()
  const [error, setError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)

    const formData = new FormData(event.currentTarget)
    const parsed = loginSchema.safeParse({
      login: formData.get('login'),
      password: formData.get('password'),
    })

    if (!parsed.success) {
      setError(parsed.error.issues[0]?.message ?? 'Datos inválidos.')
      return
    }

    setIsSubmitting(true)
    try {
      const { user } = await login(parsed.data)
      await refresh()
      // RN-014: con contraseña temporal lo único permitido es cambiarla.
      router.replace(user?.must_change_password ? CHANGE_PASSWORD_PATH : '/')
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setError(err.firstError('login') ?? err.message)
      } else if (err instanceof RateLimitError) {
        setError(err.message)
      } else {
        setError('No fue posible iniciar sesión. Intenta de nuevo.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <Card className="w-full max-w-sm">
      <CardHeader className="text-center">
        <FlameIcon className="mx-auto size-8 text-primary" aria-hidden />
        <CardTitle className="text-xl">ActivosB31</CardTitle>
        <CardDescription>Inventario de la Estación de Bomberos de Madrid</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="login">Usuario o correo</Label>
            <Input id="login" name="login" autoComplete="username" required />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="password">Contraseña</Label>
            <Input id="password" name="password" type="password" autoComplete="current-password" required />
          </div>
          {error && (
            <p role="alert" className="text-sm text-destructive">
              {error}
            </p>
          )}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Ingresando…' : 'Ingresar'}
          </Button>
        </form>
      </CardContent>
    </Card>
  )
}
