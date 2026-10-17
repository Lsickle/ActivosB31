'use client'

import { createContext, useCallback, useContext, useEffect, useState } from 'react'
import { useRouter } from 'solito/navigation'
import { logout as apiLogout, me, type AuthUser } from '../../features/auth/api'

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

export type RequireAuthResult = AuthContextValue & {
  /** true solo con sesión cargada, usuario presente y (si se pidió) el permiso. */
  isAuthorized: boolean
}

/**
 * Para pantallas que exigen sesión: redirige a /login si no hay usuario, o a
 * / si le falta `requiredPermission`. Es solo UX — el backend responde 401/403
 * de todas formas.
 */
export function useRequireAuth(requiredPermission?: string): RequireAuthResult {
  const auth = useAuth()
  const router = useRouter()
  const isAllowed = !requiredPermission || Boolean(auth.user?.permissions.includes(requiredPermission))

  useEffect(() => {
    if (auth.isLoading) return
    if (!auth.user) {
      router.replace('/login')
      return
    }
    if (!isAllowed) {
      router.replace('/')
    }
  }, [auth.isLoading, auth.user, isAllowed, router])

  return {
    ...auth,
    isAuthorized: !auth.isLoading && Boolean(auth.user) && isAllowed,
  }
}
