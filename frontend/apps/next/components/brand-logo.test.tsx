import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { BrandLogo, BRAND_LOGO_ALT, BRAND_LOGO_SRC } from './brand-logo'

describe('BrandLogo', () => {
  it('muestra el logo de la estación desde /logo-b31.png con texto alternativo', () => {
    render(<BrandLogo />)
    const logo = screen.getByRole('img', { name: 'Estación de Bomberos de Madrid' })
    expect(BRAND_LOGO_ALT).toBe('Estación de Bomberos de Madrid')
    expect(BRAND_LOGO_SRC).toBe('/logo-b31.png')
    expect(logo).toHaveAttribute('src', '/logo-b31.png')
    expect(logo).toHaveClass('object-contain')
  })

  it('decorativo: alt vacío y aria-hidden (fuera del árbol accesible)', () => {
    const { container } = render(<BrandLogo decorative />)
    const logo = container.querySelector('img[data-slot="brand-logo"]')!
    expect(logo).toHaveAttribute('src', '/logo-b31.png')
    expect(logo).toHaveAttribute('alt', '')
    expect(logo).toHaveAttribute('aria-hidden', 'true')
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })

  it('acepta clases extra para el tamaño', () => {
    render(<BrandLogo className="size-12" />)
    expect(screen.getByRole('img', { name: BRAND_LOGO_ALT })).toHaveClass('size-12')
  })
})
