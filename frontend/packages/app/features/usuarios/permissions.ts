// Regla de la matriz rol × módulo (RN-009 / FR-002): "gestionar" implica "ver".
// El servidor también normaliza (D-010); aquí se aplica para que la casilla
// refleje de inmediato lo que se va a guardar.
export type PermissionAction = 'ver' | 'gestionar'

export function togglePermission(current: string[], module: string, action: PermissionAction, checked: boolean): string[] {
  const ver = `${module}.ver`
  const gestionar = `${module}.gestionar`
  const next = new Set(current)

  if (checked) {
    next.add(ver)
    if (action === 'gestionar') next.add(gestionar)
  } else {
    next.delete(gestionar)
    if (action === 'ver') next.delete(ver)
  }

  return Array.from(next).sort()
}

export function hasSamePermissions(a: string[], b: string[]): boolean {
  if (a.length !== b.length) return false
  const set = new Set(a)
  return b.every((code) => set.has(code))
}
