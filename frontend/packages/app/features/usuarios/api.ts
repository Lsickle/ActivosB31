import { apiFetch } from '../../lib/api-client'
import { jsonBody as json, query, type Paginated } from '../../lib/api-helpers'
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
  // RN-034 (003): ISO 8601; null si no está bloqueada o el bloqueo ya venció.
  locked_until: string | null
  // RN-015 (004 D-005): empleado vinculado, opcional.
  employee: { id: number; full_name: string } | null
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

export type { Paginated, PaginationMeta } from '../../lib/api-helpers'

// Rol precargado que siempre tiene todos los permisos (RN-010).
export const ADMIN_ROLE_CODE = 'admin'

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

// RN-034 / FR-004 (003): sin efecto si la cuenta no estaba bloqueada.
export function unlockUser(id: number): Promise<{ data: UserRecord }> {
  return apiFetch(`/api/users/${id}/unlock`, { method: 'POST' })
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

// ---- Bitácora de seguridad (003-seguridad-bitacora, FR-008; solo usuarios.gestionar) ----

export type SecurityEventUser = { id: number; name: string; username: string }

export type SecurityEvent = {
  id: number
  event: string
  event_label: string
  created_at: string
  actor: SecurityEventUser | null
  target_user: SecurityEventUser | null
  target_role: { id: number; name: string } | null
  // Texto ingresado en un login fallido (la cuenta puede no existir).
  login_attempted: string | null
  ip_address: string | null
  user_agent: string | null
  metadata: Record<string, unknown> | null
}

export type SecurityEventType = { code: string; label: string }

export type ListSecurityEventsParams = {
  event?: string
  // Actor o afectado.
  userId?: number
  // YYYY-MM-DD, inclusivas.
  from?: string
  to?: string
  page?: number
  perPage?: number
}

export function listSecurityEvents(params: ListSecurityEventsParams = {}): Promise<Paginated<SecurityEvent>> {
  return apiFetch(
    `/api/security-events${query({
      event: params.event,
      user_id: params.userId,
      from: params.from,
      to: params.to,
      page: params.page,
      per_page: params.perPage,
    })}`
  )
}

export function listSecurityEventTypes(): Promise<{ data: SecurityEventType[] }> {
  return apiFetch('/api/security-events/types')
}
