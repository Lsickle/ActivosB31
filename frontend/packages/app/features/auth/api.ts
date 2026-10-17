import { apiFetch } from '../../lib/api-client'
import type { ChangePasswordValues, LoginFormValues } from './schemas'

export type AuthRole = {
  id: number
  code: string | null
  name: string
}

// Contrato de GET /api/user y POST /api/login (specs/001-rbac/contracts/api.md).
// `permissions` son los permisos efectivos (RN-009); el Administrador recibe
// los 13. `email` es opcional desde 001-rbac.
export type AuthUser = {
  id: number
  name: string
  username: string
  email: string | null
  role: AuthRole | null
  permissions: string[]
  must_change_password: boolean
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

// PUT /api/user/password — FR-009, FR-010, FR-011 (RN-014).
export function changePassword(values: ChangePasswordValues): Promise<{ message: string }> {
  return apiFetch('/api/user/password', { method: 'PUT', body: JSON.stringify(values) })
}
