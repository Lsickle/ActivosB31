import { ChevronLeftIcon, ChevronRightIcon } from 'lucide-react'
import type { PaginationMeta } from 'app/lib/api-helpers'
import { Button } from '@/components/ui/button'

// Paginación por el servidor (`meta` de Laravel), igual que en los demás listados.
export function Pager({
  meta,
  singular,
  plural,
  onPage,
}: {
  meta: PaginationMeta | null
  singular: string
  plural: string
  onPage: (page: number) => void
}) {
  if (!meta || meta.total === 0) return null
  return (
    <div className="flex items-center justify-end gap-2 text-sm text-muted-foreground">
      <span>
        Página {meta.current_page} de {meta.last_page} · {meta.total} {meta.total === 1 ? singular : plural}
      </span>
      <Button
        type="button"
        size="icon-sm"
        variant="outline"
        aria-label="Página anterior"
        disabled={meta.current_page <= 1}
        onClick={() => onPage(meta.current_page - 1)}
      >
        <ChevronLeftIcon />
      </Button>
      <Button
        type="button"
        size="icon-sm"
        variant="outline"
        aria-label="Página siguiente"
        disabled={meta.current_page >= meta.last_page}
        onClick={() => onPage(meta.current_page + 1)}
      >
        <ChevronRightIcon />
      </Button>
    </div>
  )
}
