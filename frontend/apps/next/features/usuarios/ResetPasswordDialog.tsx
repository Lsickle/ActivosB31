'use client'

import { useState } from 'react'
import { resetUserPassword, type UserRecord } from 'app/features/usuarios/api'
import { newPasswordSchema, PASSWORD_MIN_LENGTH } from 'app/features/auth/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { PasswordInput } from '@/components/ui/password-input'

// RN-014 / FR-009 / FR-011: el Administrador asigna una contraseña temporal;
// el servidor marca el cambio obligatorio y cierra las sesiones del usuario.
export function ResetPasswordDialog({
  open,
  onOpenChange,
  user,
  onDone,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  user: UserRecord | null
  onDone: (user: UserRecord) => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {open && user && <ResetPasswordForm user={user} onCancel={() => onOpenChange(false)} onDone={onDone} />}
      </DialogContent>
    </Dialog>
  )
}

function ResetPasswordForm({ user, onCancel, onDone }: { user: UserRecord; onCancel: () => void; onDone: (user: UserRecord) => void }) {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const parsed = newPasswordSchema.safeParse({
      password: formData.get('password') ?? '',
      password_confirmation: formData.get('password_confirmation') ?? '',
    })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      await resetUserPassword(user.id, parsed.data)
      onDone(user)
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setErrors(apiFieldErrors(err))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible restablecer la contraseña.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>Restablecer contraseña</DialogTitle>
        <DialogDescription>
          Asigna una contraseña temporal a {user.name} ({user.username}). Se cerrarán sus sesiones abiertas y deberá
          cambiarla en su próximo ingreso.
        </DialogDescription>
      </DialogHeader>
      <FormField
        id="reset-password"
        label="Contraseña temporal"
        error={errors.password}
        description={`Mínimo ${PASSWORD_MIN_LENGTH} caracteres, con letras y números.`}
      >
        <PasswordInput {...fieldA11yProps('reset-password', errors.password, true)} name="password" autoComplete="new-password" required />
      </FormField>
      <FormField id="reset-password-confirmation" label="Confirmar contraseña" error={errors.password_confirmation}>
        <PasswordInput
          {...fieldA11yProps('reset-password-confirmation', errors.password_confirmation)}
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
      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Guardando…' : 'Restablecer'}
        </Button>
      </DialogFooter>
    </form>
  )
}
