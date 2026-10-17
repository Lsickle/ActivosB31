'use client'

import { useState } from 'react'
import { createRole, updateRole, type RoleRecord } from 'app/features/usuarios/api'
import { roleFormSchema } from 'app/features/usuarios/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { cn } from '@/lib/utils'

// US4: crear o editar un rol (nombre y descripción). Los permisos se asignan
// en la matriz. `role` = null crea uno nuevo.
export function RoleFormDialog({
  open,
  onOpenChange,
  role,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  role: RoleRecord | null
  onSaved: (role: RoleRecord, created: boolean) => void
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        {/* Se monta de nuevo en cada apertura: el formulario arranca con los datos del rol. */}
        {open && <RoleForm role={role} onCancel={() => onOpenChange(false)} onSaved={onSaved} />}
      </DialogContent>
    </Dialog>
  )
}

function RoleForm({
  role,
  onCancel,
  onSaved,
}: {
  role: RoleRecord | null
  onCancel: () => void
  onSaved: (role: RoleRecord, created: boolean) => void
}) {
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const isSystemRole = Boolean(role?.is_system)

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const parsed = roleFormSchema.safeParse({
      // RN-020: un rol del sistema no se renombra (el input va deshabilitado y
      // no viaja en el FormData); se reenvía su nombre actual.
      name: isSystemRole && role ? role.name : (formData.get('name') ?? ''),
      description: formData.get('description') ?? '',
    })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      const { data } = role ? await updateRole(role.id, parsed.data) : await createRole(parsed.data)
      onSaved(data, !role)
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setErrors(apiFieldErrors(err))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible guardar el rol.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
      <DialogHeader>
        <DialogTitle>{role ? 'Editar rol' : 'Nuevo rol'}</DialogTitle>
        <DialogDescription>
          {role ? 'Cambia el nombre o la descripción del rol.' : 'El rol se crea sin permisos; asígnalos en la pestaña Permisos.'}
        </DialogDescription>
      </DialogHeader>
      <FormField
        id="role-name"
        label="Nombre"
        error={errors.name}
        description={isSystemRole ? 'Los roles precargados no se pueden renombrar.' : undefined}
      >
        <Input
          {...fieldA11yProps('role-name', errors.name, isSystemRole)}
          name="name"
          defaultValue={role?.name ?? ''}
          maxLength={80}
          disabled={isSystemRole}
          required
        />
      </FormField>
      <FormField
        id="role-description"
        label={
          <>
            Descripción <span className="font-normal text-muted-foreground">(opcional)</span>
          </>
        }
        error={errors.description}
      >
        <textarea
          {...fieldA11yProps('role-description', errors.description)}
          name="description"
          defaultValue={role?.description ?? ''}
          maxLength={500}
          rows={3}
          className={cn(
            'w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1.5 text-sm transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:bg-input/30'
          )}
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
          {isSubmitting ? 'Guardando…' : 'Guardar'}
        </Button>
      </DialogFooter>
    </form>
  )
}
