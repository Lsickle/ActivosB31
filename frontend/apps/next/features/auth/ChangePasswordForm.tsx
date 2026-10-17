'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { KeyRoundIcon } from 'lucide-react'
import { toast } from 'sonner'
import { changePassword } from 'app/features/auth/api'
import { changePasswordSchema, PASSWORD_MIN_LENGTH } from 'app/features/auth/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { useRequireAuth } from 'app/provider/auth'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { PasswordInput } from '@/components/ui/password-input'

// RN-014 / FR-009 / FR-010: pantalla de cambio de contraseña. Es obligatoria
// con contraseña temporal (useRequireAuth redirige aquí) y también sirve para
// el cambio voluntario desde el menú del usuario.
export function ChangePasswordForm() {
  const router = useRouter()
  const { user, isAuthorized, refresh, logout } = useRequireAuth(undefined, { allowPendingPasswordChange: true })
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  if (!isAuthorized || !user) {
    return null
  }

  const isPending = user.must_change_password

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const parsed = changePasswordSchema.safeParse({
      current_password: formData.get('current_password'),
      password: formData.get('password'),
      password_confirmation: formData.get('password_confirmation'),
    })

    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      await changePassword(parsed.data)
      await refresh()
      toast.success('Contraseña actualizada.')
      router.replace('/')
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setErrors(apiFieldErrors(err))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible cambiar la contraseña.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleLogout() {
    await logout()
    router.push('/login')
  }

  return (
    <Card className="w-full max-w-sm">
      <CardHeader className="text-center">
        <KeyRoundIcon className="mx-auto size-8 text-primary" aria-hidden />
        <CardTitle className="text-xl">Cambiar contraseña</CardTitle>
        <CardDescription>
          {isPending
            ? 'Debes cambiar tu contraseña temporal antes de continuar.'
            : 'Las demás sesiones abiertas con tu cuenta se cerrarán.'}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
          <FormField
            id="current_password"
            label="Contraseña actual"
            error={errors.current_password}
            description={isPending ? 'Es la contraseña temporal que te asignaron.' : undefined}
          >
            <PasswordInput
              {...fieldA11yProps('current_password', errors.current_password, isPending)}
              name="current_password"
              autoComplete="current-password"
              required
            />
          </FormField>
          <FormField
            id="password"
            label="Nueva contraseña"
            error={errors.password}
            description={`Mínimo ${PASSWORD_MIN_LENGTH} caracteres, con letras y números.`}
          >
            <PasswordInput
              {...fieldA11yProps('password', errors.password, true)}
              name="password"
              autoComplete="new-password"
              required
            />
          </FormField>
          <FormField id="password_confirmation" label="Confirmar nueva contraseña" error={errors.password_confirmation}>
            <PasswordInput
              {...fieldA11yProps('password_confirmation', errors.password_confirmation)}
              name="password_confirmation"
              autoComplete="new-password"
              required
            />
          </FormField>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? 'Guardando…' : 'Cambiar contraseña'}
          </Button>
          {isPending ? (
            <Button type="button" variant="ghost" onClick={handleLogout}>
              Cerrar sesión
            </Button>
          ) : (
            <Button type="button" variant="ghost" onClick={() => router.push('/')}>
              Cancelar
            </Button>
          )}
        </form>
      </CardContent>
    </Card>
  )
}
