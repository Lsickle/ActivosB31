import { describe, expect, it } from 'vitest'
// eslint-disable-next-line @typescript-eslint/no-require-imports
const vercelConfig = require('./vercel.json')

// Pendiente de seguridad 1 (CLAUDE.md): en Vercel, /api y /sanctum van al
// backend con el secreto compartido (X-Proxy-Secret, TrustVercelProxy). El
// secreto y la URL salen de variables de entorno, nunca del repo. Debe
// probarse en un preview antes del primer deploy (docs/despliegue.md).
describe('proxy de vercel.json', () => {
  const [route] = vercelConfig.routes

  it('solo reenvía /api y /sanctum al backend definido en BACKEND_URL', () => {
    expect(vercelConfig.routes).toHaveLength(1)
    expect(route.src).toBe('/(api|sanctum)/(.*)')
    expect(route.dest).toBe('${BACKEND_URL}/$1/$2')
    // A1: la ruta solo interpola BACKEND_URL (en dest); el secreto se
    // declara en la transformación que lo usa, no en la ruta.
    expect(route.env).toEqual(['BACKEND_URL'])
  })

  it('agrega X-Proxy-Secret desde VERCEL_PROXY_SECRET y no cachea el rewrite', () => {
    expect(route.transforms).toEqual([
      {
        type: 'request.headers',
        op: 'set',
        target: { key: 'x-proxy-secret' },
        args: '$VERCEL_PROXY_SECRET',
        env: ['VERCEL_PROXY_SECRET'],
      },
    ])
    expect(route.headers).toEqual({ 'x-vercel-enable-rewrite-caching': '0' })
  })
})
