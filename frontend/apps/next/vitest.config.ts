import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
  resolve: {
    tsconfigPaths: true,
  },
  plugins: [react()],
  test: {
    environment: 'jsdom',
    setupFiles: ['./vitest.setup.ts'],
    globals: true,
    // Los menús de Base UI a veces no abren tras el clic sintético en CI.
    retry: process.env.CI ? 2 : 0,
    // packages/app no tiene harness propio: sus tests corren desde aquí.
    include: ['**/*.{test,spec}.?(c|m)[jt]s?(x)', '../../packages/app/**/*.{test,spec}.?(c|m)[jt]s?(x)'],
  },
})
