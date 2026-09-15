/**
 * Previsualización de documentos A4 en una ventana aparte (no modal).
 *
 * La ventana se abre en el mismo gesto del clic (si no, el navegador la
 * bloquea) y se rellena cuando el documento está listo. El botón Imprimir de
 * la ventana avisa a la que la abrió, que es quien conoce la impresora del
 * puesto y el bridge de Electron.
 */

import { onUnmounted, ref } from 'vue'

export const PREVIEW_MSG_IMPRIMIR = 'descartes-preview-imprimir'
export const PREVIEW_MSG_ERROR = 'descartes-preview-error'

/**
 * Ventana de previsualización asociada a una vista: la abre, la rellena y
 * traduce su botón Imprimir en la llamada que ya usaba el modal.
 */
export function useVentanaPreviewDocumento(opciones: {
  imprimir: () => void | Promise<void>
}) {
  const ventana = ref<Window | null>(null)

  function onMensaje(e: MessageEvent) {
    if (!ventana.value || e.source !== ventana.value) return
    if ((e.data as { tipo?: string } | null)?.tipo === PREVIEW_MSG_IMPRIMIR) {
      void opciones.imprimir()
    }
  }

  window.addEventListener('message', onMensaje)
  onUnmounted(() => {
    window.removeEventListener('message', onMensaje)
    cerrar()
  })

  /** Debe llamarse dentro del gesto del clic o el navegador bloquea la ventana. */
  function abrir(titulo: string): boolean {
    cerrar()
    ventana.value = abrirVentanaPreview(titulo)
    return ventana.value !== null
  }

  function mostrar(
    html: string,
    opts: { impresoraNombre: string; puedeImprimir?: boolean }
  ): void {
    if (!ventana.value || ventana.value.closed) return
    escribirVentanaPreview(ventana.value, html, {
      impresoraNombre: opts.impresoraNombre,
      puedeImprimir: opts.puedeImprimir !== false,
    })
  }

  function notificarError(mensaje: string): void {
    if (!ventana.value || ventana.value.closed) return
    ventana.value.postMessage({ tipo: PREVIEW_MSG_ERROR, mensaje }, '*')
  }

  function cerrar(): void {
    if (ventana.value && !ventana.value.closed) ventana.value.close()
    ventana.value = null
  }

  return { abrir, mostrar, notificarError, cerrar }
}

/** Abre la ventana con el mismo tamaño que tenía el modal de previsualización. */
export function abrirVentanaPreview(titulo: string): Window | null {
  const ancho = Math.min(940, window.screen.availWidth - 40)
  const alto = Math.round(window.innerHeight * 0.92)
  const ventana = window.open('about:blank', '_blank', `width=${ancho},height=${alto}`)
  if (!ventana) return null

  try {
    ventana.document.write(
      `<!doctype html><html><head><meta charset="utf-8"/><title>${escapar(titulo)}</title></head>` +
        '<body style="margin:0;padding:1.5rem;font:0.9rem \'Segoe UI\',Arial,sans-serif;color:#334155">' +
        'Preparando documento…</body></html>'
    )
    ventana.document.close()
  } catch {
    // Electron a veces abre about:blank antes de que document.write esté listo.
  }
  return ventana
}

/** Vuelca un PDF (informe) en la ventana abierta, con barra Cerrar. */
export function escribirVentanaPdf(ventana: Window, blob: Blob, titulo: string): void {
  const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))
  const html = `<!doctype html>
<html>
<head>
  <meta charset="utf-8"/>
  <title>${escapar(titulo)}</title>
  <style>
    html, body { margin: 0; height: 100%; background: #e2e8f0; }
    .preview-barra {
      position: fixed; top: 0; left: 0; right: 0; z-index: 10;
      display: flex; align-items: center; gap: 0.75rem;
      padding: 0.5rem 0.9rem; background: #fff; border-bottom: 1px solid #cbd5e1;
      font: 0.82rem "Segoe UI", Arial, sans-serif; color: #475569;
    }
    .preview-barra strong { color: #0f172a; }
    .preview-acciones { margin-left: auto; display: flex; gap: 0.5rem; }
    .preview-barra button, .preview-barra a {
      padding: 0.35rem 0.8rem; border-radius: 6px; border: 1px solid #cbd5e1;
      background: #fff; cursor: pointer; font-size: 0.82rem; color: #0f172a;
      text-decoration: none;
    }
    iframe { position: absolute; top: 2.7rem; left: 0; right: 0; bottom: 0; width: 100%; height: calc(100% - 2.7rem); border: 0; }
  </style>
</head>
<body>
  <div class="preview-barra">
    <strong>${escapar(titulo)}</strong>
    <span class="preview-acciones">
      <a href="${url}" download="diario-facturacion.pdf">Descargar</a>
      <button type="button" id="preview-cerrar">Cerrar</button>
    </span>
  </div>
  <iframe src="${url}" title="${escapar(titulo)}"></iframe>
  <script>
    document.getElementById('preview-cerrar').addEventListener('click', function () { window.close(); });
    window.addEventListener('unload', function () { try { URL.revokeObjectURL(${JSON.stringify(url)}); } catch (e) {} });
  <\/script>
</body>
</html>`
  ventana.document.open()
  ventana.document.write(html)
  ventana.document.close()
  ventana.focus()
}

/** Vuelca el documento A4 en la ventana y engancha la barra de acciones. */
export function escribirVentanaPreview(
  ventana: Window,
  html: string,
  opciones: { impresoraNombre: string; puedeImprimir: boolean }
): void {
  const barra =
    '<div class="preview-barra">' +
    '<span>Impresora: <strong>' +
    escapar(opciones.impresoraNombre || '— (sin asignar en el puesto)') +
    '</strong></span>' +
    '<span class="preview-error" id="preview-error"></span>' +
    '<span class="preview-acciones">' +
    (opciones.puedeImprimir
      ? '<button type="button" id="preview-imprimir" class="primary">Imprimir</button>'
      : '') +
    '<button type="button" id="preview-cerrar">Cerrar</button>' +
    '</span></div>'

  const estilos = `<style>
  @media screen {
    html, body { width: auto; background: #e2e8f0; }
    body { padding: 3.2rem 0 1.5rem; }
    .folio { margin: 0 auto 1rem; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.25); }
    .preview-barra {
      position: fixed; top: 0; left: 0; right: 0; z-index: 10;
      display: flex; align-items: center; gap: 0.75rem;
      padding: 0.5rem 0.9rem; background: #fff; border-bottom: 1px solid #cbd5e1;
      font: 0.82rem "Segoe UI", Arial, sans-serif; color: #475569;
    }
    .preview-acciones { margin-left: auto; display: flex; gap: 0.5rem; }
    .preview-error { color: #b91c1c; }
    .preview-barra button {
      padding: 0.35rem 0.8rem; border-radius: 6px; border: 1px solid #cbd5e1;
      background: #fff; cursor: pointer; font-size: 0.82rem;
    }
    .preview-barra button.primary { background: #0f766e; border-color: #0f766e; color: #fff; }
    .preview-barra button:disabled { opacity: 0.55; cursor: not-allowed; }
  }
  @media print { .preview-barra { display: none; } .folio { margin: 0; box-shadow: none; } }
</style>`

  const script = `<script>
  (function () {
    var btn = document.getElementById('preview-imprimir');
    var err = document.getElementById('preview-error');
    if (btn) {
      btn.addEventListener('click', function () {
        err.textContent = '';
        btn.disabled = true;
        btn.textContent = 'Imprimiendo…';
        if (window.opener && !window.opener.closed) {
          window.opener.postMessage({ tipo: '${PREVIEW_MSG_IMPRIMIR}' }, '*');
        } else {
          window.print();
          btn.disabled = false;
          btn.textContent = 'Imprimir';
        }
      });
    }
    document.getElementById('preview-cerrar').addEventListener('click', function () {
      window.close();
    });
    window.addEventListener('message', function (e) {
      if (!e.data || e.data.tipo !== '${PREVIEW_MSG_ERROR}') return;
      err.textContent = String(e.data.mensaje || 'No se pudo imprimir');
      if (btn) { btn.disabled = false; btn.textContent = 'Imprimir'; }
    });
  })();
<\/script>`

  // Replacers de función: el texto insertado puede llevar '$' (nombre de impresora)
  // y en un reemplazo por cadena tendría significado especial.
  const documento = html
    .replace('</head>', () => `${estilos}</head>`)
    .replace('<body>', () => `<body>${barra}`)
    .replace('</body>', () => `${script}</body>`)

  ventana.document.open()
  ventana.document.write(documento)
  ventana.document.close()
  ventana.focus()
}

function escapar(texto: string): string {
  return texto
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}
