import { z } from 'zod'

// Reglas de specs/004-modulo-empleados (FR-002, FR-003, edge cases; RN-021) y
// del bloque 004 de esquema-bd. El backend valida igual (y la unicidad
// tipo + número, D-002); esto adelanta los mensajes junto al campo.

// FR-002: tipos de documento admitidos.
export const DOCUMENT_TYPES = ['CC', 'CE', 'TI', 'PA', 'PPT'] as const

export type DocumentType = (typeof DOCUMENT_TYPES)[number]

export const DOCUMENT_TYPE_LABELS: Record<DocumentType, string> = {
  CC: 'Cédula de ciudadanía',
  CE: 'Cédula de extranjería',
  TI: 'Tarjeta de identidad',
  PA: 'Pasaporte',
  PPT: 'Permiso por protección temporal',
}

// Texto opcional recortado: vacío → null.
function optionalText(max: number, message: string) {
  return z
    .string()
    .trim()
    .max(max, message)
    .transform((value) => (value === '' ? null : value))
}

// Edge case / D-002: se guardan solo letras y dígitos, en mayúsculas (los
// pasaportes pueden tener letras). Varchar(20) en el esquema.
const documentNumberSchema = z
  .string()
  .transform((value) => value.toUpperCase().replace(/[^A-Z0-9]/g, ''))
  .pipe(
    z.string().min(1, 'Ingresa el número de documento.').max(20, 'El número de documento admite máximo 20 caracteres.')
  )

const optionalEmailSchema = z
  .string()
  .trim()
  .transform((value) => (value === '' ? null : value.toLowerCase()))
  .pipe(z.email('Ingresa un correo válido.').max(255, 'El correo admite máximo 255 caracteres.').nullable())

const optionalPhoneSchema = optionalText(30, 'El teléfono admite máximo 30 caracteres.').refine(
  (value) => value === null || /^[0-9+()\s-]+$/.test(value),
  'Usa solo números, espacios y los signos + ( ) -.'
)

// Fecha local de hoy en formato ISO (YYYY-MM-DD), para comparar con el input date.
export function todayIsoDate(now: Date = new Date()): string {
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

// Edge case: la fecha de ingreso es opcional y no puede ser futura.
const hireDateSchema = z
  .string()
  .trim()
  .transform((value) => (value === '' ? null : value))
  .pipe(z.iso.date('Ingresa una fecha válida.').nullable())
  .refine((value) => value === null || value <= todayIsoDate(), 'La fecha de ingreso no puede ser futura.')

const sizeSchema = optionalText(10, 'La talla admite máximo 10 caracteres.')

export const employeeFormSchema = z.object({
  document_type: z.enum(DOCUMENT_TYPES, { error: 'Selecciona el tipo de documento.' }),
  document_number: documentNumberSchema,
  first_name: z.string().trim().min(1, 'Ingresa los nombres.').max(80, 'Los nombres admiten máximo 80 caracteres.'),
  last_name: z.string().trim().min(1, 'Ingresa los apellidos.').max(80, 'Los apellidos admiten máximo 80 caracteres.'),
  position_id: z.number({ error: 'Selecciona un cargo.' }).int().positive('Selecciona un cargo.'),
  company_id: z.number().int().positive().nullable(),
  phone: optionalPhoneSchema,
  email: optionalEmailSchema,
  hire_date: hireDateSchema,
  shirt_size: sizeSchema,
  pants_size: sizeSchema,
  boot_size: sizeSchema,
  glove_size: sizeSchema,
})

export type EmployeeFormValues = z.output<typeof employeeFormSchema>
