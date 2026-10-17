import { apiFetch } from '../../lib/api-client'
import type { LoginFormValues } from './schemas'

// Contrato de AuthController::me() / login() en el backend.
export type AuthUser = {
  id: number
  name: string
  username: string
  email: string
  permissions: string[]
}

export function login(values: LoginFormValues): Promise<{ user: AuthUser }> {
  return apiFetch('/api/login', { method: 'POST', body: JSON.stringify(values) })
}

export function logout(): Promise<{ message: string }> {
  return apiFetch('/api/logout', { method: 'POST' })
}

export function me(): Promise<{ user: AuthUser }> {
  return apiFetch('/api/user')
}
