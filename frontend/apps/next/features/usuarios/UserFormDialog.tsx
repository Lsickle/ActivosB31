'use client'

import { useState } from 'react'
import { createUser, updateUser, type RoleRecord, type UserRecord } from 'app/features/usuarios/api'
import { PASSWORD_MIN_LENGTH } from 'app/features/auth/schemas'
import { userCreateSchema, userUpdateSchema } from 'app/features/usuarios/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { PasswordInput } from '@/components/ui/password-input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

// US2: crear o editar un usuario. Un solo rol (RN-013); al crear se asigna
// una contraseña temporal que el usuario debe cambiar (RN-014). `user` = null crea.
export function UserFormDialog({
  open,
  onOpenChange,
  user,
  roles,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  user: UserRecord | null
  roles: RoleRecord[]
  onSaved: (user: UserRecord, created: boolean) => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        {open && <UserForm user={user} roles={roles} onCancel={() => onOpenChange(false)} onSaved={onSaved} />}
      </DialogContent>
    </Dialog>
  )
}

function UserForm({
  user,
  roles,
  onCancel,
  onSaved,
}: {
  user: UserRecord | null
  roles: RoleRecord[]
  onCancel: () => void
  onSaved: (user: UserRecord, created: boolean) => void
}) {
  const isCreating = user === null
  const [roleId, setRoleId] = useState<string | null>(user?.role ? String(user.role.id) : null)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  // US4 escenario 3: solo roles activos. RN-017: al editar se conserva el rol
  // actual del usuario aunque esté inactivo, marcado como tal.
  const roleOptions = roles.filter((role) => role.is_active || role.id === user?.role?.id)
  const roleItems = roleOptions.map((role) => ({
    value: String(role.id),
    label: role.is_active ? role.name : `${role.name} (inactivo)`,
  }))

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const values = {
      name: formData.get('name') ?? '',
      username: formData.get('username') ?? '',
      email: formData.get('email') ?? '',
      role_id: roleId ? Number(roleId) : undefined,
    }

    const parsed = isCreating
      ? userCreateSchema.safeParse({
          ...values,
          password: formData.get('password') ?? '',
          password_confirmation: formData.get('password_confirmation') ?? '',
        })
      : userUpdateSchema.safeParse(values)

    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      const { data } = isCreating
        ? await createUser(parsed.data as Parameters<typeof createUser>[0])
        : await updateUser(user.id, parsed.data)
      onSaved(data, isCreating)
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setErrors(apiFieldErrors(err))
      } else {
        // 409: p. ej. quitarle el rol al último Administrador activo (FR-012).
        setFormError(err instanceof Error ? err.message : 'No fue posible guardar el usuario.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>{isCreating ? 'Nuevo usuario' : 'Editar usuario'}</DialogTitle>
        <DialogDescription>
          {isCreating
            ? 'El usuario deberá cambiar la contraseña temporal en su primer ingreso.'
            : 'Los cambios de rol aplican desde la siguiente acción del usuario.'}
        </DialogDescription>
      </DialogHeader>

      <FormField id="user-name" label="Nombre" error={errors.name}>
        <Input {...fieldA11yProps('user-name', errors.name)} name="name" defaultValue={user?.name ?? ''} maxLength={120} required />
      </FormField>

      <div className="grid gap-4 sm:grid-cols-2">
        <FormField
          id="user-username"
          label="Nombre de usuario"
          error={errors.username}
          description="3 a 40 caracteres: letras, números, punto, guion o guion bajo."
        >
          <Input
            {...fieldA11yProps('user-username', errors.username, true)}
            name="username"
            defaultValue={user?.username ?? ''}
            autoComplete="off"
            autoCapitalize="none"
            spellCheck={false}
            maxLength={40}
            required
          />
        </FormField>
        <FormField
          id="user-email"
          label={
            <>
              Correo <span className="font-normal text-muted-foreground">(opcional)</span>
            </>
          }
          error={errors.email}
        >
          <Input
            {...fieldA11yProps('user-email', errors.email)}
            name="email"
            type="email"
            defaultValue={user?.email ?? ''}
            autoComplete="off"
          />
        </FormField>
      </div>

      <div className="flex flex-col gap-2">
        <Label id="user-role-label">Rol</Label>
        <Select items={roleItems} value={roleId} onValueChange={(value) => setRoleId(value as string | null)}>
          <SelectTrigger
            className="w-full"
            aria-labelledby="user-role-label"
            aria-invalid={errors.role_id ? true : undefined}
            aria-describedby={errors.role_id ? 'user-role-error' : undefined}
          >
            <SelectValue placeholder="Selecciona un rol" />
          </SelectTrigger>
          <SelectContent>
            {roleItems.map((item) => (
              <SelectItem key={item.value} value={item.value}>
                {item.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {errors.role_id && (
          <p id="user-role-error" className="text-xs text-destructive">
            {errors.role_id}
          </p>
        )}
      </div>

      {isCreating && (
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField
            id="user-password"
            label="Contraseña temporal"
            error={errors.password}
            description={`Mínimo ${PASSWORD_MIN_LENGTH} caracteres, con letras y números.`}
          >
            <PasswordInput {...fieldA11yProps('user-password', errors.password, true)} name="password" autoComplete="new-password" required />
          </FormField>
          <FormField id="user-password-confirmation" label="Confirmar contraseña" error={errors.password_confirmation}>
            <PasswordInput
              {...fieldA11yProps('user-password-confirmation', errors.password_confirmation)}
              name="password_confirmation"
              autoComplete="new-password"
              required
            />
          </FormField>
        </div>
      )}

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
          {isSubmitting ? 'Guardando…' : isCreating ? 'Crear usuario' : 'Guardar'}
        </Button>
      </DialogFooter>
    </form>
  )
}
