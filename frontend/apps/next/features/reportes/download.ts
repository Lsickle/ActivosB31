import type { DownloadedFile } from 'app/lib/api-client'

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
    // Se libera después del clic: algunos navegadores leen la URL de forma asíncrona.
    setTimeout(() => URL.revokeObjectURL(url), 0)
  }
}
