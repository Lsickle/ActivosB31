const path = require('path')
/**
 * @type {import('next').NextConfig}
 */
const withWebpack = {
  webpack(config) {
    if (!config.resolve) {
      config.resolve = {}
    }

    config.resolve.alias = {
      ...(config.resolve.alias || {}),
      'react-native': 'react-native-web',
      'react-native$': 'react-native-web',
      'react-native/Libraries/EventEmitter/RCTDeviceEventEmitter$':
        'react-native-web/dist/vendor/react-native/NativeEventEmitter/RCTDeviceEventEmitter',
      'react-native/Libraries/vendor/emitter/EventEmitter$':
        'react-native-web/dist/vendor/react-native/emitter/EventEmitter',
      'react-native/Libraries/EventEmitter/NativeEventEmitter$':
        'react-native-web/dist/vendor/react-native/NativeEventEmitter',
    }

    config.resolve.extensions = [
      '.web.js',
      '.web.jsx',
      '.web.ts',
      '.web.tsx',
      ...(config.resolve?.extensions ?? []),
    ]

    return config
  },
}

/**
 * @type {import('next').NextConfig}
 */
const withTurpopack = {
  turbopack: {
    resolveAlias: {
      'react-native': 'react-native-web',
      'react-native/Libraries/EventEmitter/RCTDeviceEventEmitter$':
        'react-native-web/dist/vendor/react-native/NativeEventEmitter/RCTDeviceEventEmitter',
      'react-native/Libraries/vendor/emitter/EventEmitter$':
        'react-native-web/dist/vendor/react-native/emitter/EventEmitter',
      'react-native/Libraries/EventEmitter/NativeEventEmitter$':
        'react-native-web/dist/vendor/react-native/NativeEventEmitter',
    },
    resolveExtensions: [
      '.web.js',
      '.web.jsx',
      '.web.ts',
      '.web.tsx',

      '.js',
      '.mjs',
      '.tsx',
      '.ts',
      '.jsx',
      '.json',
      '.wasm',
    ],
    root: path.resolve(__dirname, '../..'),
  },
}

// Proxy de /api y /sanctum hacia el backend (Render) cuando frontend y
// backend viven en dominios distintos (Vercel sin dominio propio compartido):
// así las cookies de sesión de Sanctum quedan first-party para el navegador
// en vez de cross-site, que los navegadores descartan. En Vercel se define
// BACKEND_URL y NEXT_PUBLIC_API_URL vacío (mismo origen). Sin BACKEND_URL
// (dev local con Sail, mismo host "localhost") no se activa ningún rewrite.
async function proxyRewrites() {
  const backendUrl = process.env.BACKEND_URL
  if (!backendUrl) {
    return []
  }

  return [
    { source: '/api/:path*', destination: `${backendUrl}/api/:path*` },
    { source: '/sanctum/:path*', destination: `${backendUrl}/sanctum/:path*` },
  ]
}

/**
 * @type {import('next').NextConfig}
 */
module.exports = {
  rewrites: proxyRewrites,
  transpilePackages: ['react-native', 'react-native-web', 'solito'],

  compiler: {
    define: {
      __DEV__: JSON.stringify(process.env.NODE_ENV !== 'production'),
    },
  },
  reactStrictMode: true,

  ...withWebpack,
  ...withTurpopack,
}
