import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Toaster } from 'sonner'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ExportButtons } from './shared'

const saveFile = vi.fn()
vi.mock('./download', () => ({ saveFile: (...args: unknown[]) => saveFile(...args) }))

// 008 D-002: una descarga fallida siempre avisa con un toast. Integración real
// (ExportButtons → reportsApi.download → apiDownload → sonner); solo se
// simula fetch: la primera llamada es /sanctum/csrf-cookie y la segunda el
// reporte.
const request = { kind: 'inventory', filters: {} } as const

function respondDownloadWith(response: Response | Error) {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (url: string) => {
      if (url.endsWith('/sanctum/csrf-cookie')) return new Response(null, { status: 204 })
      if (response instanceof Error) throw response
      return response
    })
  )
}

describe('ExportButtons: errores de descarga', () => {
  beforeEach(() => {
    saveFile.mockReset()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  function renderButtons() {
    render(
      <>
        <ExportButtons request={request} ready />
        <Toaster />
      </>
    )
  }

  it('un 500 con cuerpo HTML muestra un toast genérico en español y libera el botón', async () => {
    respondDownloadWith(new Response('<html>Internal Server Error</html>', { status: 500, headers: { 'Content-Type': 'text/html' } }))
    renderButtons()

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en PDF' }))

    expect(await screen.findByText('No fue posible descargar el reporte.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Descargar en PDF' })).toBeEnabled()
    expect(saveFile).not.toHaveBeenCalled()
  })

  it('un 500 con el JSON genérico de Laravel ("Server Error") no muestra el texto técnico', async () => {
    respondDownloadWith(Response.json({ message: 'Server Error' }, { status: 500 }))
    renderButtons()

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en Excel' }))

    expect(await screen.findByText('No fue posible descargar el reporte.')).toBeInTheDocument()
    expect(screen.queryByText('Server Error')).not.toBeInTheDocument()
  })

  it('un fallo de red también avisa', async () => {
    respondDownloadWith(new TypeError('Failed to fetch'))
    renderButtons()

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en PDF' }))

    expect(await screen.findByText('No fue posible descargar el reporte.')).toBeInTheDocument()
  })

  it('R-2: el 422 por reporte demasiado grande muestra el mensaje del servidor', async () => {
    const message = 'El reporte es demasiado grande: acota el rango de fechas o los filtros.'
    respondDownloadWith(Response.json({ message, errors: { from: [message] } }, { status: 422 }))
    renderButtons()

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en PDF' }))

    expect(await screen.findByText(message)).toBeInTheDocument()
  })

  it('el 429 muestra cuándo reintentar', async () => {
    respondDownloadWith(Response.json({ message: 'Too Many Attempts.' }, { status: 429, headers: { 'Retry-After': '42' } }))
    renderButtons()

    await userEvent.click(screen.getByRole('button', { name: 'Descargar en Excel' }))

    expect(await screen.findByText('Demasiados intentos. Intenta de nuevo en 42 segundos.')).toBeInTheDocument()
  })
})
