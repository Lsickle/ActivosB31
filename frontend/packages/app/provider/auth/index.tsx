'use client'

import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { useRouter } from 'solito/navigation'
import { logout as apiLogout, me, type AuthUser } from '../../features/auth/api'
import { CHANGE_PASSWORD_PATH } from '../../features/auth/paths'
import { onAuthError } from '../../lib/api-client'

// Estado global de sesión, hidratado con GET /api/user al montar. Vive en
// packages/app para reutilizarlo en una futura app móvil (Expo).
type AuthContextValue = {
  user: AuthUser | null
  isLoading: boolean
  refresh: () => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [isLoading, setIsLoading] = useState(true)

  const refresh = useCallback(async () => {
    try {
      const { user } = await me()
      setUser(user)
    } catch {
      // Sin sesión (401) o error de red: se trata igual, como "no logueado".
      setUser(null)
    } finally {
      setIsLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  // Cualquier pantalla puede descubrir que la sesión cambió en el servidor:
  // 401 = sesión revocada (FR-011) → se limpia y useRequireAuth lleva al login;
  // 403 password_change_required (RN-014) → se recarga el usuario y
  // useRequireAuth lleva a /cambiar-contrasena; 403 sin code (B-7: permiso
  // retirado desde la matriz) → se recarga el usuario para que el menú y los
  // botones reflejen los permisos vigentes.
  useEffect(
    () =>
      onAuthError((kind) => {
        if (kind === 'unauthenticated') {
          setUser(null)
        } else {
          refresh()
        }
      }),
    [refresh]
  )

  const logout = useCallback(async () => {
    try {
      await apiLogout()
    } finally {
      setUser(null)
    }
  }, [])

  return <AuthContext.Provider value={{ user, isLoading, refresh, logout }}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth debe usarse dentro de un AuthProvider.')
  }
  return context
}

/**
 * RN-009: ¿el usuario actual tiene el permiso `code` (p. ej. 'usuarios.gestionar')?
 * El backend ya entrega los permisos efectivos (el Administrador recibe los 13),
 * así que basta con buscarlo. Es solo UX: el servidor valida cada operación.
 */
export function useCan(code: string): boolean {
  const { user } = useAuth()
  return Boolean(user?.permissions.includes(code))
}

export type RequireAuthOptions = {
  /** Solo para /cambiar-contrasena: no redirige allí si el cambio está pendiente. */
  allowPendingPasswordChange?: boolean
}

export type RequireAuthResult = AuthContextValue & {
  /** true solo con sesión cargada, usuario presente, sin cambio de contraseña pendiente y (si se pidió) el permiso. */
  isAuthorized: boolean
  /** true con sesión válida pero sin `requiredPermission`: la pantalla muestra "Acceso denegado". */
  isForbidden: boolean
}

/**
 * Para pantallas que exigen sesión: redirige a /login si no hay usuario y a
 * /cambiar-contrasena si tiene la contraseña temporal pendiente (RN-014).
 * Si le falta `requiredPermission` no redirige: devuelve `isForbidden` para
 * que la pantalla muestre "Acceso denegado" (US5). Es solo UX — el backend
 * responde 401/403 de todas formas.
 */
export function useRequireAuth(requiredPermission?: string, options: RequireAuthOptions = {}): RequireAuthResult {
  const auth = useAuth()
  const router = useRouter()
  const { allowPendingPasswordChange = false } = options
  const mustChangePassword = Boolean(auth.user?.must_change_password) && !allowPendingPasswordChange
  const hasPermission = !requiredPermission || Boolean(auth.user?.permissions.includes(requiredPermission))
  const isReady = !auth.isLoading && Boolean(auth.user) && !mustChangePassword

  useEffect(() => {
    if (auth.isLoading) return
    if (!auth.user) {
      router.replace('/login')
      return
    }
    if (mustChangePassword) {
      router.replace(CHANGE_PASSWORD_PATH)
    }
  }, [auth.isLoading, auth.user, mustChangePassword, router])

  return {
    ...auth,
    isAuthorized: isReady && hasPermission,
    isForbidden: isReady && !hasPermission,
  }
}
