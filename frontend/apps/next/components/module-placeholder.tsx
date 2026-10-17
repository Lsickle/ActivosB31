'use client'

import { useRequireAuth } from 'app/provider/auth'
import { AccessDenied } from '@/components/access-denied'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

// Marcador de un módulo todavía no implementado (ver docs/modulos.md). Ya
// queda protegido con su permiso `<modulo>.ver` (US5).
export function ModulePlaceholder({
  title,
  description,
  permission,
}: {
  title: string
  description: string
  permission?: string
}) {
  const { isAuthorized, isForbidden } = useRequireAuth(permission)

  if (isForbidden) {
    return <AccessDenied />
  }

  if (!isAuthorized) {
    return null
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
    </Card>
  )
}
