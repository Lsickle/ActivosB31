import { describe, expect, it } from 'vitest'
import { roleFormSchema, userCreateSchema, userUpdateSchema } from './schemas'

const validUser = {
  name: 'Juan Pérez',
  username: 'JPerez',
  email: '',
  role_id: 2,
  password: 'Temporal123',
  password_confirmation: 'Temporal123',
}

function firstMessage(result: { success: boolean; error?: { issues: { message: string }[] } }) {
  return result.error?.issues[0]?.message
}

describe('userCreateSchema (FR-008, FR-009, FR-010)', () => {
  it('normaliza username y correo a minúsculas y deja el correo vacío en null', () => {
    const parsed = userCreateSchema.parse({ ...validUser, email: '  JPerez@Bomberos.GOV.co ' })

    expect(parsed.username).toBe('jperez')
    expect(parsed.email).toBe('jperez@bomberos.gov.co')
    expect(userCreateSchema.parse(validUser).email).toBeNull()
  })

  it('rechaza "@" en el nombre de usuario con un mensaje propio', () => {
    expect(firstMessage(userCreateSchema.safeParse({ ...validUser, username: 'a@b' }))).toBe(
      'El nombre de usuario no puede contener "@".'
    )
  })

  it('rechaza caracteres fuera de [a-z0-9._-] y longitudes fuera de 3–40', () => {
    expect(userCreateSchema.safeParse({ ...validUser, username: 'juan perez' }).success).toBe(false)
    expect(userCreateSchema.safeParse({ ...validUser, username: 'jp' }).success).toBe(false)
    expect(userCreateSchema.safeParse({ ...validUser, username: 'j'.repeat(41) }).success).toBe(false)
  })

  it('exige rol y contraseña temporal que cumpla la política', () => {
    expect(firstMessage(userCreateSchema.safeParse({ ...validUser, role_id: null }))).toBe('Selecciona un rol.')
    expect(firstMessage(userCreateSchema.safeParse({ ...validUser, password: 'solo-letras-aqui', password_confirmation: 'solo-letras-aqui' }))).toBe(
      'La contraseña debe incluir al menos un número.'
    )
  })

  it('rechaza un correo con formato inválido', () => {
    expect(firstMessage(userCreateSchema.safeParse({ ...validUser, email: 'no-es-correo' }))).toBe('Ingresa un correo válido.')
  })
})

describe('userUpdateSchema', () => {
  it('no pide contraseña', () => {
    const { password: _p, password_confirmation: _c, ...rest } = validUser
    expect(userUpdateSchema.safeParse(rest).success).toBe(true)
  })
})

describe('roleFormSchema', () => {
  it('exige nombre y deja la descripción vacía en null', () => {
    expect(roleFormSchema.parse({ name: ' Comandante ', description: '' })).toEqual({ name: 'Comandante', description: null })
    expect(firstMessage(roleFormSchema.safeParse({ name: '  ', description: '' }))).toBe('Ingresa el nombre del rol.')
  })
})
