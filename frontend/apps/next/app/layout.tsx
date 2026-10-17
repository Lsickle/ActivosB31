import { StylesProvider } from './styles-provider'
import './globals.css'
import { Geist } from 'next/font/google'
import { cn } from '@/lib/utils'
import { ThemeProvider } from '@/components/theme-provider'
import { TooltipProvider } from '@/components/ui/tooltip'
import { Toaster } from '@/components/ui/sonner'
import { AuthProvider } from 'app/provider/auth'

const geist = Geist({ subsets: ['latin'], variable: '--font-sans' })

export const metadata = {
  title: {
    template: 'ActivosB31 - %s',
    default: 'ActivosB31',
  },
  description: 'Gestión de inventario y activos de la Estación de Bomberos de Madrid, Cundinamarca',
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="es" className={cn('font-sans', geist.variable)} suppressHydrationWarning>
      <body>
        <ThemeProvider>
          <AuthProvider>
            <TooltipProvider>
              <StylesProvider>{children}</StylesProvider>
              <Toaster />
            </TooltipProvider>
          </AuthProvider>
        </ThemeProvider>
      </body>
    </html>
  )
}
