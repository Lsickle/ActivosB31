import { apiFetch } from '../../lib/api-client'
import { jsonBody, query, type Paginated } from '../../lib/api-helpers'
import type { DocumentType, EmployeeFormValues } from './schemas'

// Contrato: specs/004-modulo-empleados/plan.md.
// Ver = empleados.ver; crear/editar/estado = empleados.gestionar (RN-022).
// No hay eliminación: se desactivan (US1-5), conservan su historial de entregas.

type CatalogRef = { id: number; name: string; is_active: boolean }

// EmployeeResource (plan.md). Contiene datos personales (Ley 1581, RN-022).
export type EmployeeRecord = {
  id: number
  document_type: DocumentType
  document_number: string
  first_name: string
  last_name: string
  full_name: string
  position: CatalogRef
  company: CatalogRef | null
  phone: string | null
  email: string | null
  /** YYYY-MM-DD */
  hire_date: string | null
  shirt_size: string | null
  pants_size: string | null
  boot_size: string | null
  glove_size: string | null
  is_active: boolean
  user: { id: number; username: string } | null
  created_at: string
  updated_at: string
}

export type EmployeeListParams = {
  search?: string
  positionId?: number
  companyId?: number
  isActive?: boolean
  page?: number
  perPage?: number
}

export const employeesApi = {
  // FR-001: paginado, orden por apellidos y nombres (lo hace el servidor).
  list: (params: EmployeeListParams = {}): Promise<Paginated<EmployeeRecord>> =>
    apiFetch(
      `/api/employees${query({
        search: params.search?.trim(),
        position_id: params.positionId,
        company_id: params.companyId,
        is_active: params.isActive,
        page: params.page,
        per_page: params.perPage,
      })}`
    ),
  create: (values: EmployeeFormValues): Promise<{ data: EmployeeRecord }> => apiFetch('/api/employees', jsonBody('POST', values)),
  update: (id: number, values: EmployeeFormValues): Promise<{ data: EmployeeRecord }> =>
    apiFetch(`/api/employees/${id}`, jsonBody('PATCH', values)),
  updateStatus: (id: number, isActive: boolean): Promise<{ data: EmployeeRecord }> =>
    apiFetch(`/api/employees/${id}/status`, jsonBody('PATCH', { is_active: isActive })),
}

// FR-006 / D-004: lista mínima de empleados activos para otros módulos
// (Entregas, Reportes, vínculo de usuario), sin datos personales.
export type EmployeeOption = { id: number; full_name: string; position: string }

export function employeesOptions(): Promise<{ data: EmployeeOption[] }> {
  return apiFetch('/api/employees/options')
}
