/* eslint-disable @next/next/no-img-element -- PNG estático de public/: next/image no aporta optimización a un logo pequeño y exige width/height fijos. */
import { cn } from '@/lib/utils'

// Logo de la Estación de Bomberos de Madrid. El archivo vive en
// apps/next/public/logo-b31.png (lo entrega el usuario).
export const BRAND_LOGO_SRC = '/logo-b31.png'
export const BRAND_LOGO_ALT = 'Estación de Bomberos de Madrid'

/**
 * `decorative`: el logo va junto a un texto que ya identifica la estación o la
 * app (sidebar, barra superior, Inicio); se oculta a lectores de pantalla para
 * no anunciarlo varias veces por página.
 */
export function BrandLogo({ className, decorative = false }: { className?: string; decorative?: boolean }) {
  return (
    <img
      src={BRAND_LOGO_SRC}
      alt={decorative ? '' : BRAND_LOGO_ALT}
      aria-hidden={decorative || undefined}
      data-slot="brand-logo"
      className={cn('size-8 shrink-0 object-contain', className)}
    />
  )
}
