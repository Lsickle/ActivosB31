import type { z } from 'zod'
import type { ApiValidationError } from './api-client'

export type FieldErrors = Record<string, string>

// Primer mensaje por campo de un error de zod (validación en el cliente).
export function zodFieldErrors(error: z.ZodError): FieldErrors {
  const errors: FieldErrors = {}
  for (const issue of error.issues) {
    const key = String(issue.path[0] ?? '_form')
    errors[key] ??= issue.message
  }
  return errors
}

// Primer mensaje por campo de un 422 de Laravel (`errors: {campo: [..]}`).
export function apiFieldErrors(error: ApiValidationError): FieldErrors {
  const errors: FieldErrors = {}
  for (const [field, messages] of Object.entries(error.errors)) {
    if (messages[0]) errors[field] = messages[0]
  }
  return errors
}
