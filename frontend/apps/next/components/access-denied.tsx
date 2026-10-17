import { ShieldAlertIcon } from 'lucide-react'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

// US5 escenario 2: quien navega a un módulo sin su permiso "Ver" ve este
// mensaje en lugar de una redirección silenciosa.
export function AccessDenied() {
  return (
    <Card>
      <CardHeader>
        <ShieldAlertIcon className="size-6 text-destructive" aria-hidden />
        <CardTitle>
          <h1>Acceso denegado</h1>
        </CardTitle>
        <CardDescription>No tienes permiso para ver este módulo. Si lo necesitas, pídeselo a un Administrador.</CardDescription>
      </CardHeader>
    </Card>
  )
}
