import type { DownloadedFile } from 'app/lib/api-client'

// R-10 (revisión de seguridad de 008): espera antes de liberar el object URL.
export const REVOKE_DELAY_MS = 30_000

// 008 D-002: entrega al navegador un archivo descargado con fetch (apiDownload
// lleva la cookie de sesión y el CSRF; un <a href> directo no serviría con el
// backend en otro origen). Vive en apps/next porque usa el DOM.
export function saveFile(file: DownloadedFile, fallbackName: string): void {
  const url = URL.createObjectURL(file.blob)
  const link = document.createElement('a')
  link.href = url
  link.download = file.filename ?? fallbackName
  link.rel = 'noopener'
  link.style.display = 'none'
  document.body.appendChild(link)
  try {
    link.click()
  } finally {
    link.remove()
    // R-10: se libera 30 s después del clic. Algunos navegadores (Safari,
    // Firefox con archivos grandes) leen la URL de forma asíncrona y con 0 ms
    // la descarga podía fallar; 30 s no retiene memoria de forma notable.
    setTimeout(() => URL.revokeObjectURL(url), REVOKE_DELAY_MS)
  }
}
