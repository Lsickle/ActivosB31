import { describe, expect, it } from 'vitest'
import { DOCUMENT_TYPE_LABELS, DOCUMENT_TYPES, employeeFormSchema } from './schemas'

const valid = {
  document_type: 'CC',
  document_number: '1234567',
  first_name: 'Ana',
  last_name: 'Pérez',
  position_id: 2,
  company_id: null,
  phone: '',
  email: '',
  hire_date: '',
  shirt_size: '',
  pants_size: '',
  boot_size: '',
  glove_size: '',
}

function errorFor(input: Record<string, unknown>, field: string): string | undefined {
  const result = employeeFormSchema.safeParse({ ...valid, ...input })
  if (result.success) return undefined
  return result.error.issues.find((issue) => issue.path[0] === field)?.message
}

// specs/004-modulo-empleados: FR-002, FR-003, edge cases; RN-021.
describe('employeeFormSchema (FR-002, FR-003, RN-021)', () => {
  it('ofrece los cinco tipos de documento con su etiqueta (FR-002)', () => {
    expect(DOCUMENT_TYPES).toEqual(['CC', 'CE', 'TI', 'PA', 'PPT'])
    expect(DOCUMENT_TYPE_LABELS).toEqual({
      CC: 'Cédula de ciudadanía',
      CE: 'Cédula de extranjería',
      TI: 'Tarjeta de identidad',
      PA: 'Pasaporte',
      PPT: 'Permiso por protección temporal',
    })
    expect(errorFor({ document_type: 'NIT' }, 'document_type')).toBe('Selecciona el tipo de documento.')
  })

  it('acepta los obligatorios y convierte los opcionales vacíos en null', () => {
    const result = employeeFormSchema.parse(valid)
    expect(result).toEqual({
      document_type: 'CC',
      document_number: '1234567',
      first_name: 'Ana',
      last_name: 'Pérez',
      position_id: 2,
      company_id: null,
      phone: null,
      email: null,
      hire_date: null,
      shirt_size: null,
      pants_size: null,
      boot_size: null,
      glove_size: null,
    })
  })

  it('normaliza el número de documento a letras y dígitos en mayúsculas (edge case, D-002)', () => {
    expect(employeeFormSchema.parse({ ...valid, document_number: ' 1.234.567 ' }).document_number).toBe('1234567')
    expect(employeeFormSchema.parse({ ...valid, document_type: 'PA', document_number: 'ab-12 34' }).document_number).toBe('AB1234')
    expect(errorFor({ document_number: ' .-. ' }, 'document_number')).toBe('Ingresa el número de documento.')
    expect(errorFor({ document_number: '1'.repeat(21) }, 'document_number')).toBe('El número de documento admite máximo 20 caracteres.')
  })

  it('exige nombres y apellidos de máximo 80 caracteres, recortados', () => {
    expect(errorFor({ first_name: '  ' }, 'first_name')).toBe('Ingresa los nombres.')
    expect(errorFor({ last_name: '' }, 'last_name')).toBe('Ingresa los apellidos.')
    expect(errorFor({ first_name: 'a'.repeat(81) }, 'first_name')).toBe('Los nombres admiten máximo 80 caracteres.')
    expect(employeeFormSchema.parse({ ...valid, first_name: '  Ana María ' }).first_name).toBe('Ana María')
  })

  it('exige cargo; la compañía es opcional', () => {
    expect(errorFor({ position_id: undefined }, 'position_id')).toBe('Selecciona un cargo.')
    expect(employeeFormSchema.parse({ ...valid, company_id: 4 }).company_id).toBe(4)
  })

  it('valida correo y teléfono solo si vienen (edge case)', () => {
    expect(errorFor({ email: 'no-es-correo' }, 'email')).toBe('Ingresa un correo válido.')
    expect(employeeFormSchema.parse({ ...valid, email: ' Ana@Bomberos.CO ' }).email).toBe('ana@bomberos.co')
    expect(errorFor({ phone: '1'.repeat(31) }, 'phone')).toBe('El teléfono admite máximo 30 caracteres.')
    expect(errorFor({ phone: 'llamar a Ana' }, 'phone')).toBe('Usa solo números, espacios y los signos + ( ) -.')
    expect(employeeFormSchema.parse({ ...valid, phone: ' +57 (601) 555-1234 ' }).phone).toBe('+57 (601) 555-1234')
  })

  it('rechaza una fecha de ingreso futura (edge case)', () => {
    expect(errorFor({ hire_date: '2999-01-01' }, 'hire_date')).toBe('La fecha de ingreso no puede ser futura.')
    expect(errorFor({ hire_date: '31/12/2020' }, 'hire_date')).toBe('Ingresa una fecha válida.')
    expect(employeeFormSchema.parse({ ...valid, hire_date: '2020-03-15' }).hire_date).toBe('2020-03-15')
  })

  it('acepta tallas de texto libre de máximo 10 caracteres (edge case)', () => {
    expect(employeeFormSchema.parse({ ...valid, pants_size: ' 32x30 ' }).pants_size).toBe('32x30')
    expect(errorFor({ boot_size: '12345678901' }, 'boot_size')).toBe('La talla admite máximo 10 caracteres.')
  })
})
