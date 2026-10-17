import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

// Marcador de un módulo todavía no implementado (ver docs/modulos.md).
export function ModulePlaceholder({ title, description }: { title: string; description: string }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
    </Card>
  )
}
