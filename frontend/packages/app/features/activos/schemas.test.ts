import { describe, expect, it } from 'vitest'
import {
  ASSET_STATUS_LABELS,
  MAX_PHOTO_BYTES,
  assetFormSchema,
  assetUpdateSchema,
  expirySettingsSchema,
  locationChangeSchema,
  maintenancePlanSchema,
  maintenanceRecordSchema,
  manualTransitions,
  photoError,
  statusChangeSchema,
} from './schemas'

const TODAY = new Date()
const tomorrow = new Date(TODAY.getFullYear(), TODAY.getMonth(), TODAY.getDate() + 1)
const TOMORROW_ISO = `${tomorrow.getFullYear()}-${String(tomorrow.getMonth() + 1).padStart(2, '0')}-${String(tomorrow.getDate()).padStart(2, '0')}`

const validAsset = {
  asset_type_id: 3,
  plate: ' b31-0001 ',
  serial: '',
  brand: '',
  model: '',
  acquisition_date: '',
  acquisition_value: '',
  useful_life_months: '',
  expires_on: '',
  location_id: 4,
  notes: '',
}

function issues(result: { success: boolean; error?: { issues: { path: PropertyKey[]; message: string }[] } }) {
  return Object.fromEntries((result.error?.issues ?? []).map((issue) => [String(issue.path[0]), issue.message]))
}

describe('esquemas de activos (005)', () => {
  it('etiqueta los cuatro estados de RN-005', () => {
    expect(ASSET_STATUS_LABELS).toEqual({
      available: 'Disponible',
      assigned: 'Asignado',
      maintenance: 'En mantenimiento',
      retired: 'Dado de baja',
    })
  })

  it('D-001: transiciones manuales; Asignado y Dado de baja no tienen ninguna', () => {
    expect(manualTransitions('available')).toEqual(['maintenance', 'retired'])
    expect(manualTransitions('maintenance')).toEqual(['available', 'retired'])
    expect(manualTransitions('assigned')).toEqual([])
    expect(manualTransitions('retired')).toEqual([])
  })

  it('RN-008 / D-006: placa obligatoria, recortada y en mayúsculas; opcionales vacíos → null', () => {
    expect(assetFormSchema.parse(validAsset)).toEqual({
      asset_type_id: 3,
      plate: 'B31-0001',
      serial: null,
      brand: null,
      model: null,
      acquisition_date: null,
      acquisition_value: null,
      useful_life_months: null,
      expires_on: null,
      location_id: 4,
      notes: null,
    })

    expect(issues(assetFormSchema.safeParse({ ...validAsset, plate: '   ' }))).toMatchObject({ plate: 'Ingresa la placa de inventario.' })
    expect(issues(assetFormSchema.safeParse({ ...validAsset, plate: 'X'.repeat(51) }))).toMatchObject({
      plate: 'La placa admite máximo 50 caracteres.',
    })
  })

  it('exige el tipo de activo; la ubicación es opcional (assets.location_id NULL)', () => {
    expect(issues(assetFormSchema.safeParse({ ...validAsset, asset_type_id: undefined }))).toMatchObject({
      asset_type_id: 'Selecciona el tipo de activo.',
    })
    expect(assetFormSchema.parse({ ...validAsset, location_id: null }).location_id).toBeNull()
  })

  it('la edición no lleva ubicación (se cambia con su acción propia, D-002)', () => {
    expect(assetUpdateSchema.parse(validAsset)).not.toHaveProperty('location_id')
  })

  it('limita serial (100), marca y modelo (80)', () => {
    const result = assetFormSchema.safeParse({ ...validAsset, serial: 'S'.repeat(101), brand: 'B'.repeat(81), model: 'M'.repeat(81) })
    expect(issues(result)).toMatchObject({
      serial: 'El serial admite máximo 100 caracteres.',
      brand: 'La marca admite máximo 80 caracteres.',
      model: 'El modelo admite máximo 80 caracteres.',
    })
  })

  it('edge cases: fecha de adquisición futura, valor negativo y vida útil ≤ 0 se rechazan', () => {
    const result = assetFormSchema.safeParse({
      ...validAsset,
      acquisition_date: TOMORROW_ISO,
      acquisition_value: '-1',
      useful_life_months: '0',
    })
    expect(issues(result)).toMatchObject({
      acquisition_date: 'La fecha de adquisición no puede ser futura.',
      acquisition_value: 'El valor no puede ser negativo.',
      useful_life_months: 'La vida útil debe ser un número entero de meses mayor que 0.',
    })

    expect(issues(assetFormSchema.safeParse({ ...validAsset, useful_life_months: '1.5' }))).toMatchObject({
      useful_life_months: 'La vida útil debe ser un número entero de meses mayor que 0.',
    })
    expect(issues(assetFormSchema.safeParse({ ...validAsset, acquisition_value: 'abc' }))).toMatchObject({
      acquisition_value: 'Ingresa un valor numérico.',
    })
  })

  it('convierte valor, vida útil y fechas válidas; el vencimiento (RN-031) puede ser futuro', () => {
    const parsed = assetFormSchema.parse({
      ...validAsset,
      acquisition_date: '2024-02-10',
      acquisition_value: '1250000.50',
      useful_life_months: '120',
      expires_on: '2034-02-10',
    })
    expect(parsed).toMatchObject({
      acquisition_date: '2024-02-10',
      acquisition_value: 1250000.5,
      useful_life_months: 120,
      expires_on: '2034-02-10',
    })
    expect(issues(assetFormSchema.safeParse({ ...validAsset, expires_on: '2034-02-31' }))).toMatchObject({
      expires_on: 'Ingresa una fecha válida.',
    })
  })

  it('foto: solo JPEG, PNG o WebP de máximo 4 MB (límite del proxy de Vercel)', () => {
    expect(MAX_PHOTO_BYTES).toBe(4 * 1024 * 1024)
    expect(photoError({ type: 'image/jpeg', size: 1000 })).toBeNull()
    expect(photoError({ type: 'image/png', size: MAX_PHOTO_BYTES })).toBeNull()
    expect(photoError({ type: 'image/webp', size: 10 })).toBeNull()
    expect(photoError({ type: 'image/gif', size: 10 })).toBe('La foto debe ser JPEG, PNG o WebP.')
    expect(photoError({ type: 'image/jpeg', size: MAX_PHOTO_BYTES + 1 })).toBe('La foto no puede superar 4 MB.')
  })

  it('D-001: el motivo es obligatorio al dar de baja', () => {
    expect(issues(statusChangeSchema.safeParse({ status: 'retired', reason: '  ' }))).toMatchObject({
      reason: 'Indica el motivo de la baja.',
    })
    expect(statusChangeSchema.parse({ status: 'retired', reason: ' Daño irreparable ' })).toEqual({
      status: 'retired',
      reason: 'Daño irreparable',
    })
    expect(statusChangeSchema.parse({ status: 'maintenance', reason: '' })).toEqual({ status: 'maintenance', reason: null })
    // Asignado solo lo pone Entregas (FR-003).
    expect(statusChangeSchema.safeParse({ status: 'assigned', reason: '' }).success).toBe(false)
  })

  it('cambio de ubicación exige una ubicación', () => {
    expect(issues(locationChangeSchema.safeParse({ location_id: undefined }))).toMatchObject({
      location_id: 'Selecciona la nueva ubicación.',
    })
    expect(locationChangeSchema.parse({ location_id: 7 })).toEqual({ location_id: 7 })
  })

  it('RN-029/RN-030: plan con nombre ≤120, periodicidad 1..600 meses y anticipación 0..365 (30 por defecto)', () => {
    expect(maintenancePlanSchema.parse({ name: ' Prueba hidrostática ', interval_months: '60', last_done_on: '2022-03-10', alert_days: '' })).toEqual({
      name: 'Prueba hidrostática',
      interval_months: 60,
      last_done_on: '2022-03-10',
      alert_days: 30,
      is_active: true,
    })

    const result = maintenancePlanSchema.safeParse({ name: '', interval_months: '0', last_done_on: TOMORROW_ISO, alert_days: '400' })
    expect(issues(result)).toEqual({
      name: 'Ingresa el nombre del mantenimiento.',
      interval_months: 'La periodicidad debe ser un número entero de meses entre 1 y 600.',
      last_done_on: 'La fecha del último realizado no puede ser futura.',
      alert_days: 'La anticipación debe ser un número entero de días entre 0 y 365.',
    })
    expect(issues(maintenancePlanSchema.safeParse({ name: 'X'.repeat(121), interval_months: '601', alert_days: '0' }))).toMatchObject({
      name: 'El nombre admite máximo 120 caracteres.',
      interval_months: 'La periodicidad debe ser un número entero de meses entre 1 y 600.',
    })
    expect(maintenancePlanSchema.parse({ name: 'Revisión', interval_months: '12', last_done_on: '', alert_days: '0', is_active: false })).toEqual({
      name: 'Revisión',
      interval_months: 12,
      last_done_on: null,
      alert_days: 0,
      is_active: false,
    })
  })

  it('registro de mantenimiento: fecha no futura, descripción obligatoria ≤500, costo ≥ 0', () => {
    expect(
      maintenanceRecordSchema.parse({
        plan_id: 5,
        performed_on: '2026-10-01',
        description: ' Prueba aprobada ',
        performed_by: '',
        cost: '150000',
      })
    ).toEqual({ plan_id: 5, performed_on: '2026-10-01', description: 'Prueba aprobada', performed_by: null, cost: 150000 })

    const result = maintenanceRecordSchema.safeParse({
      plan_id: null,
      performed_on: TOMORROW_ISO,
      description: '',
      performed_by: 'P'.repeat(121),
      cost: '-5',
    })
    expect(issues(result)).toEqual({
      performed_on: 'La fecha del mantenimiento no puede ser futura.',
      description: 'Describe el mantenimiento realizado.',
      performed_by: 'Quién lo hizo admite máximo 120 caracteres.',
      cost: 'El costo no puede ser negativo.',
    })
    expect(issues(maintenanceRecordSchema.safeParse({ plan_id: null, performed_on: '', description: 'x'.repeat(501), performed_by: '', cost: '' }))).toEqual({
      performed_on: 'Ingresa la fecha del mantenimiento.',
      description: 'La descripción admite máximo 500 caracteres.',
    })
  })

  it('D-005 / RN-030: anticipación global de vencimientos entera entre 0 y 365', () => {
    expect(expirySettingsSchema.parse({ expiry_alert_days: '45' })).toEqual({ expiry_alert_days: 45 })
    expect(issues(expirySettingsSchema.safeParse({ expiry_alert_days: '-1' }))).toEqual({
      expiry_alert_days: 'La anticipación debe ser un número entero de días entre 0 y 365.',
    })
    expect(expirySettingsSchema.safeParse({ expiry_alert_days: '' }).success).toBe(false)
  })
})
