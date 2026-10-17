import { apiFetch } from '../../lib/api-client'
import type { NewPasswordValues } from '../auth/schemas'
import type { PermissionAction } from './permissions'
import type { RoleFormValues, UserCreateValues, UserUpdateValues } from './schemas'

// Contrato: specs/001-rbac/contracts/api.md (usuarios.ver / usuarios.gestionar).

export type RoleSummary = { id: number; name: string; code: string | null }

export type UserRecord = {
  id: number
  name: string
  username: string
  email: string | null
  is_active: boolean
  must_change_password: boolean
  last_login_at: string | null
  role: RoleSummary | null
  created_at: string
}

export type RoleRecord = {
  id: number
  code: string | null
  name: string
  description: string | null
  is_system: boolean
  is_active: boolean
  users_count: number
  permissions: string[]
}

export type PermissionModule = {
  module: string
  label: string
  permissions: { code: string; action: PermissionAction }[]
}

export type PaginationMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

export type Paginated<T> = { data: T[]; meta: PaginationMeta }

// Rol precargado que siempre tiene todos los permisos (RN-010).
export const ADMIN_ROLE_CODE = 'admin'

// Laravel valida `boolean` con 1/0: no acepta "true"/"false" en query string.
function query(params: Record<string, string | number | boolean | undefined | null>): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    search.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
  }
  const result = search.toString()
  return result ? `?${result}` : ''
}

function json(method: string, body: unknown): RequestInit {
  return { method, body: JSON.stringify(body) }
}

// ---- Usuarios ----

export type ListUsersParams = {
  search?: string
  roleId?: number
  isActive?: boolean
  page?: number
  perPage?: number
}

export function listUsers(params: ListUsersParams = {}): Promise<Paginated<UserRecord>> {
  return apiFetch(
    `/api/users${query({
      search: params.search?.trim(),
      role_id: params.roleId,
      is_active: params.isActive,
      page: params.page,
      per_page: params.perPage,
    })}`
  )
}

export function createUser(values: UserCreateValues): Promise<{ data: UserRecord }> {
  return apiFetch('/api/users', json('POST', values))
}

export function updateUser(id: number, values: UserUpdateValues): Promise<{ data: UserRecord }> {
  return apiFetch(`/api/users/${id}`, json('PATCH', values))
}

export function updateUserStatus(id: number, isActive: boolean): Promise<{ data: UserRecord }> {
  return apiFetch(`/api/users/${id}/status`, json('PATCH', { is_active: isActive }))
}

export function resetUserPassword(id: number, values: NewPasswordValues): Promise<{ message: string }> {
  return apiFetch(`/api/users/${id}/password`, json('PUT', values))
}

// ---- Roles ----

export function listRoles(params: { search?: string; isActive?: boolean } = {}): Promise<{ data: RoleRecord[] }> {
  return apiFetch(`/api/roles${query({ search: params.search?.trim(), is_active: params.isActive })}`)
}

export function createRole(values: RoleFormValues): Promise<{ data: RoleRecord }> {
  return apiFetch('/api/roles', json('POST', values))
}

export function updateRole(id: number, values: RoleFormValues): Promise<{ data: RoleRecord }> {
  return apiFetch(`/api/roles/${id}`, json('PATCH', values))
}

export function updateRoleStatus(id: number, isActive: boolean): Promise<{ data: RoleRecord }> {
  return apiFetch(`/api/roles/${id}/status`, json('PATCH', { is_active: isActive }))
}

// ---- Matriz de permisos (RN-009, D-010) ----

export function listPermissions(): Promise<{ data: PermissionModule[] }> {
  return apiFetch('/api/permissions')
}

export function updateRolePermissions(id: number, permissions: string[]): Promise<{ data: RoleRecord }> {
  return apiFetch(`/api/roles/${id}/permissions`, json('PUT', { permissions }))
}
