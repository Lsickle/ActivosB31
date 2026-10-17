import { z } from 'zod'

export const loginSchema = z.object({
  login: z.string().trim().min(1, 'Ingresa tu usuario o correo.'),
  password: z.string().min(1, 'Ingresa tu contraseña.'),
})

export type LoginFormValues = z.infer<typeof loginSchema>

// FR-010: mínimo 10 caracteres con letras y números. El backend es la fuente
// de verdad (Password::min(10)->letters()->numbers()); esto solo adelanta el
// mensaje. Se reutiliza al crear usuarios y al restablecer contraseñas.
export const PASSWORD_MIN_LENGTH = 10

export const passwordPolicy = z
  .string()
  .min(PASSWORD_MIN_LENGTH, `La contraseña debe tener al menos ${PASSWORD_MIN_LENGTH} caracteres.`)
  .regex(/[A-Za-zÀ-ÖØ-öø-ÿ]/, 'La contraseña debe incluir al menos una letra.')
  .regex(/[0-9]/, 'La contraseña debe incluir al menos un número.')

const passwordConfirmation = z.string().min(1, 'Confirma la contraseña.')

export const newPasswordSchema = z
  .object({
    password: passwordPolicy,
    password_confirmation: passwordConfirmation,
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Las contraseñas no coinciden.',
    path: ['password_confirmation'],
  })

export type NewPasswordValues = z.infer<typeof newPasswordSchema>

export const changePasswordSchema = z
  .object({
    current_password: z.string().min(1, 'Ingresa tu contraseña actual.'),
    password: passwordPolicy,
    password_confirmation: passwordConfirmation,
  })
  .refine((values) => values.password !== values.current_password, {
    message: 'La nueva contraseña debe ser distinta de la actual.',
    path: ['password'],
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: 'Las contraseñas no coinciden.',
    path: ['password_confirmation'],
  })

export type ChangePasswordValues = z.infer<typeof changePasswordSchema>
