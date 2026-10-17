import { z } from 'zod'

export const loginSchema = z.object({
  login: z.string().trim().min(1, 'Ingresa tu usuario o correo.'),
  password: z.string().min(1, 'Ingresa tu contraseña.'),
})

export type LoginFormValues = z.infer<typeof loginSchema>
