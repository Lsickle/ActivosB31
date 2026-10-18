import { describe, expect, it } from 'vitest'
// eslint-disable-next-line @typescript-eslint/no-require-imports
const nextConfig = require('./next.config.js')

// B-6 (revisión de seguridad): anti-clickjacking, sin sniffing de MIME y
// referrer restringido en todas las rutas.
describe('cabeceras de seguridad de next.config.js (B-6)', () => {
  it('aplica las cabeceras a todas las rutas', async () => {
    const rules = await nextConfig.headers()
    const all = rules.find((rule: { source: string }) => rule.source === '/:path*')
    const headers = Object.fromEntries(all.headers.map((h: { key: string; value: string }) => [h.key, h.value]))

    expect(headers).toEqual({
      'X-Frame-Options': 'DENY',
      'Content-Security-Policy': "frame-ancestors 'none'",
      'X-Content-Type-Options': 'nosniff',
      'Referrer-Policy': 'same-origin',
    })
  })

  it('conserva el proxy de /api y /sanctum con BACKEND_URL', async () => {
    process.env.BACKEND_URL = 'https://api.example.test'
    const rewrites = await nextConfig.rewrites()
    delete process.env.BACKEND_URL

    expect(rewrites).toEqual([
      { source: '/api/:path*', destination: 'https://api.example.test/api/:path*' },
      { source: '/sanctum/:path*', destination: 'https://api.example.test/sanctum/:path*' },
    ])
  })

  // B2 (revisión del despliegue): en Vercel el proxy es solo el de
  // vercel.json (con X-Proxy-Secret). Si next.config.js también reenviara,
  // un fallo de vercel.json pasaría inadvertido (sin secreto ni IP real).
  it('no hace rewrites en Vercel aunque BACKEND_URL esté definido', async () => {
    process.env.BACKEND_URL = 'https://api.example.test'
    process.env.VERCEL = '1'
    const rewrites = await nextConfig.rewrites()
    delete process.env.BACKEND_URL
    delete process.env.VERCEL

    expect(rewrites).toEqual([])
  })

  it('no hace rewrites sin BACKEND_URL', async () => {
    delete process.env.BACKEND_URL
    expect(await nextConfig.rewrites()).toEqual([])
  })
})
