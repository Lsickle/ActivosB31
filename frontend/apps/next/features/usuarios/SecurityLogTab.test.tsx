import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { Paginated, SecurityEvent, UserRecord } from 'app/features/usuarios/api'
import { describeSecurityEvent, SecurityLogTab } from './SecurityLogTab'

const listSecurityEvents = vi.fn()
const listSecurityEventTypes = vi.fn()
const listUsers = vi.fn()

vi.mock('app/features/usuarios/api', async (importOriginal) => ({
  ...(await importOriginal<typeof import('app/features/usuarios/api')>()),
  listSecurityEvents: (...args: unknown[]) => listSecurityEvents(...args),
  listSecurityEventTypes: (...args: unknown[]) => listSecurityEventTypes(...args),
  listUsers: (...args: unknown[]) => listUsers(...args),
}))

const admin = { id: 1, name: 'Administrador', username: 'admin' }

function securityEvent(overrides: Partial<SecurityEvent>): SecurityEvent {
  return {
    id: 1,
    event: 'login_succeeded',
    event_label: 'Ingreso exitoso',
    created_at: '2026-10-17T15:04:05-05:00',
    actor: admin,
    target_user: admin,
    target_role: null,
    login_attempted: null,
    ip_address: '190.0.0.1',
    user_agent: 'Mozilla/5.0',
    metadata: { channel: 'web' },
    ...overrides,
  }
}

function page<T>(data: T[], meta: Partial<Paginated<T>['meta']> = {}): Paginated<T> {
  return { data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length, from: 1, to: data.length, ...meta } }
}

const events: SecurityEvent[] = [
  securityEvent({
    id: 10,
    event: 'role_permissions_updated',
    event_label: 'Matriz de permisos modificada',
    target_user: null,
    target_role: { id: 2, name: 'Almacenista' },
    metadata: { added: ['reportes.ver'], removed: ['empleados.gestionar'] },
  }),
  securityEvent({
    id: 9,
    event: 'login_failed',
    event_label: 'Ingreso fallido',
    actor: null,
    target_user: null,
    login_attempted: 'fantasma',
    ip_address: '10.0.0.5',
    metadata: { reason: 'invalid' },
  }),
  securityEvent({
    id: 8,
    event: 'user_updated',
    event_label: 'Usuario editado',
    target_user: { id: 9, name: 'Juan Pérez', username: 'jperez' },
    metadata: { changes: { email: ['juan@a.co', 'juan@b.co'], role: ['Consulta', 'Almacenista'] } },
  }),
  securityEvent({
    id: 7,
    event: 'user_status_changed',
    event_label: 'Usuario activado/desactivado',
    target_user: { id: 9, name: 'Juan Pérez', username: 'jperez' },
    metadata: { is_active: false },
  }),
]

const usersPage: Paginated<UserRecord> = page([
  {
    id: 1,
    name: 'Administrador',
    username: 'admin',
    email: null,
    is_active: true,
    must_change_password: false,
    last_login_at: null,
    role: null,
    created_at: '2026-10-17T10:00:00Z',
    locked_until: null,
    employee: null,
  },
  {
    id: 9,
    name: 'Juan Pérez',
    username: 'jperez',
    email: null,
    is_active: true,
    must_change_password: false,
    last_login_at: null,
    role: null,
    created_at: '2026-10-17T10:00:00Z',
    locked_until: null,
    employee: null,
  },
])

async function choose(comboboxName: string, optionName: string) {
  fireEvent.click(screen.getByRole('combobox', { name: comboboxName }))
  const option = await screen.findByRole('option', { name: optionName })
  await act(async () => {
    fireEvent.pointerDown(option)
    fireEvent.click(option)
  })
}

describe('SecurityLogTab (003 US3, FR-008)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    listSecurityEvents.mockResolvedValue(page(events))
    listSecurityEventTypes.mockResolvedValue({
      data: [
        { code: 'login_failed', label: 'Ingreso fallido' },
        { code: 'role_permissions_updated', label: 'Matriz de permisos modificada' },
      ],
    })
    listUsers.mockResolvedValue(usersPage)
  })

  it('lista los eventos con fecha local, evento, actor, afectado, IP y detalle legible', async () => {
    render(<SecurityLogTab />)

    const matrix = await screen.findByRole('row', { name: /Matriz de permisos modificada/ })
    const expectedDate = new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'medium' }).format(
      new Date('2026-10-17T15:04:05-05:00')
    )
    // Intl usa espacios no separables ("p. m."); Testing Library los normaliza en el DOM.
    expect(within(matrix).getByText(expectedDate.replace(/\s+/g, ' '))).toBeInTheDocument()
    expect(within(matrix).getByText('Administrador')).toBeInTheDocument()
    expect(within(matrix).getByText('Rol: Almacenista')).toBeInTheDocument()
    expect(within(matrix).getByText('190.0.0.1')).toBeInTheDocument()
    expect(within(matrix).getByText('Agregados: reportes.ver')).toBeInTheDocument()
    expect(within(matrix).getByText('Quitados: empleados.gestionar')).toBeInTheDocument()

    const failed = screen.getByRole('row', { name: /Ingreso fallido/ })
    expect(within(failed).getByText('Sistema')).toBeInTheDocument()
    expect(within(failed).getByText('Usuario ingresado: fantasma')).toBeInTheDocument()
    expect(within(failed).getByText('Motivo: credenciales inválidas')).toBeInTheDocument()

    const updated = screen.getByRole('row', { name: /Usuario editado/ })
    expect(within(updated).getByText('Juan Pérez (jperez)')).toBeInTheDocument()
    expect(within(updated).getByText('Correo: juan@a.co → juan@b.co')).toBeInTheDocument()
    expect(within(updated).getByText('Rol: Consulta → Almacenista')).toBeInTheDocument()

    expect(within(screen.getByRole('row', { name: /activado\/desactivado/ })).getByText('Desactivado')).toBeInTheDocument()

    expect(listSecurityEvents).toHaveBeenCalledWith({ event: undefined, userId: undefined, from: '', to: '', page: 1, perPage: 25 })
    expect(listUsers).toHaveBeenCalledWith({ perPage: 100 })
  })

  it('filtra por tipo de evento con el catálogo del servidor', async () => {
    render(<SecurityLogTab />)
    await screen.findByRole('row', { name: /Matriz de permisos modificada/ })

    await choose('Filtrar por tipo de evento', 'Ingreso fallido')

    await waitFor(() =>
      expect(listSecurityEvents).toHaveBeenLastCalledWith(expect.objectContaining({ event: 'login_failed', page: 1 }))
    )
  })

  it('filtra por usuario (actor o afectado)', async () => {
    render(<SecurityLogTab />)
    await screen.findByRole('row', { name: /Matriz de permisos modificada/ })

    await choose('Filtrar por usuario', 'Juan Pérez (jperez)')

    await waitFor(() => expect(listSecurityEvents).toHaveBeenLastCalledWith(expect.objectContaining({ userId: 9, page: 1 })))
  })

  it('filtra por rango de fechas', async () => {
    render(<SecurityLogTab />)
    await screen.findByRole('row', { name: /Matriz de permisos modificada/ })

    fireEvent.change(screen.getByLabelText('Desde'), { target: { value: '2026-10-01' } })
    fireEvent.change(screen.getByLabelText('Hasta'), { target: { value: '2026-10-17' } })

    await waitFor(() =>
      expect(listSecurityEvents).toHaveBeenLastCalledWith(
        expect.objectContaining({ from: '2026-10-01', to: '2026-10-17', page: 1 })
      )
    )
  })

  it('pagina con el servidor', async () => {
    listSecurityEvents.mockResolvedValue(page(events, { last_page: 3, total: 60 }))
    render(<SecurityLogTab />)
    await screen.findByText('Página 1 de 3 · 60 eventos')

    await userEvent.click(screen.getByRole('button', { name: 'Página siguiente' }))

    await waitFor(() => expect(listSecurityEvents).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 })))
  })

  it('muestra un mensaje si no hay eventos', async () => {
    listSecurityEvents.mockResolvedValue(page([]))
    render(<SecurityLogTab />)

    expect(await screen.findByText('No hay eventos que coincidan con los filtros.')).toBeInTheDocument()
  })

  it('muestra el error de carga', async () => {
    listSecurityEvents.mockRejectedValue(new Error('boom'))
    render(<SecurityLogTab />)

    expect(await screen.findByRole('alert')).toHaveTextContent('No fue posible cargar la bitácora.')
  })
})

function lines(overrides: Partial<SecurityEvent>): string[] {
  return describeSecurityEvent(securityEvent(overrides)).map((line) => line.text)
}

describe('describeSecurityEvent: eventos agregados después de 003', () => {
  it('settings_updated: anticipación de alertas de vencimiento', () => {
    expect(
      lines({
        event: 'settings_updated',
        event_label: 'Ajustes modificados',
        target_user: null,
        metadata: { changes: { expiry_alert_days: [30, 45] } },
      })
    ).toEqual(['Anticipación de alertas de vencimiento (días): 30 → 45'])
  })

  it('report_exported: reporte, formato, sujeto y filtros traducidos', () => {
    expect(
      lines({
        event: 'report_exported',
        event_label: 'Reporte exportado',
        target_user: null,
        metadata: {
          report: 'asset_type',
          subject_id: 4,
          format: 'xlsx',
          filters: { asset_category_id: 2, asset_type_id: 4, location_id: 7, status: 'available', from: '2026-10-01', to: '2026-10-17' },
          personal_data: false,
        },
      })
    ).toEqual([
      'Reporte: Por tipo de artículo',
      'Formato: Excel',
      'Sujeto: #4',
      'Filtros: Categoría=2, Tipo=4, Ubicación=7, Estado=available, Desde=2026-10-01, Hasta=2026-10-17',
    ])
  })

  it.each([
    ['inventory', 'Inventario general'],
    ['employee', 'Por empleado'],
    ['asset', 'Por activo'],
    ['asset_type', 'Por tipo de artículo'],
  ])('report_exported: nombre del reporte %s', (report, label) => {
    expect(
      lines({ event: 'report_exported', metadata: { report, subject_id: null, format: 'pdf', filters: {}, personal_data: false } })
    ).toEqual([`Reporte: ${label}`, 'Formato: PDF'])
  })

  it('report_exported: marca resaltada si incluye datos personales', () => {
    const detail = describeSecurityEvent(
      securityEvent({
        event: 'report_exported',
        metadata: { report: 'employee', subject_id: 12, format: 'pdf', filters: [], personal_data: true },
      })
    )
    expect(detail.map((line) => line.text)).toEqual(['Reporte: Por empleado', 'Formato: PDF', 'Sujeto: #12', 'Incluye datos personales'])
    expect(detail.at(-1)?.highlight).toBe(true)
    expect(detail.slice(0, -1).every((line) => !line.highlight)).toBe(true)
  })

  it('signature_integrity_failed: entrega y motivo', () => {
    expect(
      lines({
        event: 'signature_integrity_failed',
        event_label: 'Firma de entrega alterada o faltante',
        actor: null,
        target_user: null,
        metadata: { delivery_id: 5, code: 'ENT-2026-0005', reason: 'altered' },
      })
    ).toEqual(['Entrega: ENT-2026-0005', 'Motivo: firma alterada'])
    expect(
      lines({ event: 'signature_integrity_failed', metadata: { delivery_id: 6, code: 'ENT-2026-0006', reason: 'missing' } })
    ).toEqual(['Entrega: ENT-2026-0006', 'Motivo: archivo faltante'])
  })

  it('signature_integrity_failed sin código usa el id de la entrega', () => {
    expect(lines({ event: 'signature_integrity_failed', metadata: { delivery_id: 6, reason: 'missing' } })).toEqual([
      'Entrega: #6',
      'Motivo: archivo faltante',
    ])
  })

  it('delivery_pdf_exported (RN-041): entrega del acta descargada', () => {
    expect(
      lines({
        event: 'delivery_pdf_exported',
        event_label: 'Acta de entrega descargada',
        metadata: { delivery_id: 5, code: 'ENT-2026-0005' },
      })
    ).toEqual(['Entrega: ENT-2026-0005'])
    expect(lines({ event: 'delivery_pdf_exported', metadata: { delivery_id: 6 } })).toEqual(['Entrega: #6'])
  })

  it('user_created: empleado vinculado como objeto o como texto', () => {
    expect(
      lines({ event: 'user_created', metadata: { role: 'Almacenista', employee: { id: 3, name: 'Ana Gómez' } } })
    ).toEqual(['Rol: Almacenista', 'Empleado vinculado: Ana Gómez'])
    expect(lines({ event: 'user_created', metadata: { role: 'Almacenista', employee: 'Ana Gómez' } })).toEqual([
      'Rol: Almacenista',
      'Empleado vinculado: Ana Gómez',
    ])
  })

  it('user_updated: cambio de empleado vinculado como objetos, texto o null', () => {
    expect(
      lines({
        event: 'user_updated',
        metadata: { changes: { employee: [null, { id: 3, name: 'Ana Gómez' }] } },
      })
    ).toEqual(['Empleado vinculado: — → Ana Gómez'])
    expect(
      lines({ event: 'user_updated', metadata: { changes: { employee: [{ id: 3, name: 'Ana Gómez' }, 'Luis Ruiz'] } } })
    ).toEqual(['Empleado vinculado: Ana Gómez → Luis Ruiz'])
  })

  it('account_unlocked desde la consola', () => {
    expect(lines({ event: 'account_unlocked', event_label: 'Cuenta desbloqueada', actor: null, metadata: { channel: 'cli' } })).toEqual([
      'Canal: consola',
    ])
  })

  it('login_failed conserva sus motivos (no se confunden con los de la firma)', () => {
    expect(lines({ event: 'login_failed', metadata: { reason: 'locked' } })).toEqual(['Motivo: cuenta bloqueada'])
  })

  it('muestra resaltado el aviso de datos personales en la tabla', async () => {
    listSecurityEvents.mockResolvedValue(
      page([
        securityEvent({
          id: 20,
          event: 'report_exported',
          event_label: 'Reporte exportado',
          target_user: null,
          metadata: { report: 'employee', subject_id: 12, format: 'xlsx', filters: {}, personal_data: true },
        }),
      ])
    )
    render(<SecurityLogTab />)

    const row = await screen.findByRole('row', { name: /Reporte exportado/ })
    expect(within(row).getByText('Formato: Excel')).toBeInTheDocument()
    const warning = within(row).getByText('Incluye datos personales')
    expect(warning.tagName).toBe('STRONG')
  })
})
