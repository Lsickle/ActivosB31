import { z } from 'zod'
import { passwordPolicy } from '../auth/schemas'

// Reglas de specs/001-rbac/data-model.md. El backend valida igual; esto
// adelanta los mensajes y normaliza como lo hará el servidor (FR-008).

const nameSchema = z.string().trim().min(1, 'Ingresa el nombre.').max(120, 'El nombre admite máximo 120 caracteres.')

// FR-008: 3 a 40 caracteres [a-z0-9._-], sin "@" (se reserva para distinguir
// correo en el inicio de sesión), guardado en minúsculas.
const usernameSchema = z
  .string()
  .trim()
  .refine((value) => !value.includes('@'), 'El nombre de usuario no puede contener "@".')
  .pipe(
    z
      .string()
      .min(3, 'El nombre de usuario debe tener entre 3 y 40 caracteres.')
      .max(40, 'El nombre de usuario debe tener entre 3 y 40 caracteres.')
      .regex(/^[a-z0-9._-]+$/i, 'Usa solo letras sin tildes, números, punto, guion o guion bajo.')
  )
  .transform((value) => value.toLowerCase())

// Correo opcional: vacío se envía como null; si viene, formato válido y en minúsculas.
const optionalEmailSchema = z
  .string()
  .trim()
  .transform((value) => (value === '' ? null : value.toLowerCase()))
  .pipe(z.email('Ingresa un correo válido.').max(255, 'El correo admite máximo 255 caracteres.').nullable())

const roleIdSchema = z.number({ error: 'Selecciona un rol.' }).int().positive('Selecciona un rol.')

const userBaseSchema = z.object({
  name: nameSchema,
  username: usernameSchema,
  email: optionalEmailSchema,
  role_id: roleIdSchema,
})

export const userUpdateSchema = userBaseSchema

export type UserUpdateValues = z.infer<typeof userUpdateSchema>

// FR-009: al crear se asigna una contraseña temporal (cambio obligatorio).
export const userCreateSchema = userBaseSchema
  .extend({
    password: passwordPolicy,
    password_confirmation: z.string().min(1, 'Confirma la contraseña.'),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Las contraseñas no coinciden.',
    path: ['password_confirmation'],
  })

export type UserCreateValues = z.infer<typeof userCreateSchema>

export const roleFormSchema = z.object({
  name: z.string().trim().min(1, 'Ingresa el nombre del rol.').max(80, 'El nombre admite máximo 80 caracteres.'),
  description: z
    .string()
    .trim()
    .max(500, 'La descripción admite máximo 500 caracteres.')
    .transform((value) => (value === '' ? null : value)),
})

export type RoleFormValues = z.infer<typeof roleFormSchema>
