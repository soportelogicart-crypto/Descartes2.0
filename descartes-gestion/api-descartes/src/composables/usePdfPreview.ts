import { ref } from 'vue'

/** Previsualización de PDF en modal (sin popups ni descarga forzada). */
export function usePdfPreview(tituloInicial = 'Previsualización PDF') {
  const pdfOpen = ref(false)
  const pdfUrl = ref<string | null>(null)
  const pdfTitulo = ref(tituloInicial)

  function cerrarPdf() {
    pdfOpen.value = false
    if (pdfUrl.value) {
      URL.revokeObjectURL(pdfUrl.value)
      pdfUrl.value = null
    }
  }

  function abrirPdf(blob: Blob, titulo?: string) {
    if (pdfUrl.value) URL.revokeObjectURL(pdfUrl.value)
    pdfUrl.value = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))
    if (titulo) pdfTitulo.value = titulo
    pdfOpen.value = true
  }

  return { pdfOpen, pdfUrl, pdfTitulo, cerrarPdf, abrirPdf }
}
