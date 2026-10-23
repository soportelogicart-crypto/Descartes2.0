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
/** Impresión terminada bien: devuelve el botón a su estado normal. */
export const PREVIEW_MSG_RESULTADO = 'descartes-preview-resultado'

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
  opciones: { impresoraNombre: string; puedeImprimir: boolean; paginar?: boolean }
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
  .folio.pagina-hoja { position: relative; overflow: hidden; margin: 0 auto 8mm; }
  .preview-pagina {
    position: absolute; right: 11mm; bottom: 5mm;
    font: 9px Arial, Helvetica, sans-serif; color: #64748b;
  }
  @media print {
    .preview-barra { display: none; }
    .folio { margin: 0; box-shadow: none; }
    .folio.pagina-hoja {
      height: auto !important; max-height: none !important; min-height: 0 !important;
      overflow: visible !important; box-shadow: none !important; margin: 0 !important;
      break-after: page; page-break-after: always;
    }
    .folio.pagina-hoja:last-of-type { break-after: auto; page-break-after: auto; }
    .preview-pagina { display: none !important; }
  }
</style>`

  const script = `<script>
  (function () {
    var PAGINAR = ${opciones.paginar ? 'true' : 'false'};
    function paginarListado() {
      if (!PAGINAR) return 0;
      var folio = document.querySelector('main.folio') || document.querySelector('.folio');
      if (!folio) return 0;
      var cs = getComputedStyle(folio);
      var alto = parseFloat(cs.minHeight);
      if (!alto || alto < 80) return 0;
      var horizontal = parseFloat(cs.width) > alto;
      function excede(hoja) { return hoja.scrollHeight > hoja.clientHeight + 2; }
      function nueva() {
        var p = document.createElement('main');
        p.className = folio.className + ' pagina-hoja';
        p.style.height = alto + 'px';
        p.style.maxHeight = alto + 'px';
        p.style.overflow = 'hidden';
        p.style.width = horizontal ? cs.width : '';
        return p;
      }
      folio.style.height = alto + 'px';
      folio.style.maxHeight = alto + 'px';
      folio.style.overflow = 'hidden';
      if (!excede(folio)) {
        folio.classList.add('pagina-hoja');
        folio.style.overflow = 'hidden';
        marcar([folio]);
        return 1;
      }
      folio.style.height = '';
      folio.style.maxHeight = '';
      folio.style.overflow = '';
      var cabecera = [];
      var actual = nueva();
      var hojas = [actual];
      folio.before(actual);
      function añadirCabecera(p) {
        cabecera.forEach(function (n) { p.appendChild(n.cloneNode(true)); });
      }
      function saltar() {
        actual = nueva();
        hojas[hojas.length - 1].after(actual);
        hojas.push(actual);
        añadirCabecera(actual);
      }
      function colocarBloque(nodo) {
        actual.appendChild(nodo);
        if (!excede(actual)) return;
        actual.removeChild(nodo);
        saltar();
        actual.appendChild(nodo);
      }
      function colocarTabla(tabla) {
        var thead = tabla.tHead;
        var filas = [];
        var cuerpos = tabla.tBodies.length ? Array.prototype.slice.call(tabla.tBodies) : [];
        if (cuerpos.length) {
          cuerpos.forEach(function (tb) {
            Array.prototype.forEach.call(tb.rows, function (tr) { filas.push(tr); });
          });
        } else {
          Array.prototype.forEach.call(tabla.rows, function (tr) {
            if (!thead || !thead.contains(tr)) filas.push(tr);
          });
        }
        var tfoot = tabla.tFoot;
        function abrir() {
          var t = tabla.cloneNode(false);
          if (thead) t.appendChild(thead.cloneNode(true));
          var tb = document.createElement('tbody');
          t.appendChild(tb);
          actual.appendChild(t);
          if (excede(actual)) {
            actual.removeChild(t);
            saltar();
            actual.appendChild(t);
          }
          return { t: t, tb: tb };
        }
        var ctx = abrir();
        filas.forEach(function (tr) {
          ctx.tb.appendChild(tr);
          if (!excede(actual)) return;
          ctx.tb.removeChild(tr);
          if (!ctx.tb.rows.length) { ctx.tb.appendChild(tr); return; }
          saltar();
          ctx = abrir();
          ctx.tb.appendChild(tr);
        });
        if (tfoot) {
          ctx.t.appendChild(tfoot);
          if (excede(actual)) {
            ctx.t.removeChild(tfoot);
            saltar();
            ctx = abrir();
            ctx.t.appendChild(tfoot);
          }
        }
      }
      function walk(nodo, enCuerpo) {
        if (nodo.nodeType === 3) {
          if (enCuerpo && nodo.textContent.trim()) colocarBloque(nodo);
          return;
        }
        if (nodo.nodeType !== 1) return;
        var el = nodo;
        var tieneTabla = el.tagName === 'TABLE' || (el.querySelector && el.querySelector('table'));
        if (!enCuerpo && !tieneTabla) {
          cabecera.push(el);
          actual.appendChild(el);
          return;
        }
        if (el.tagName === 'TABLE') { colocarTabla(el); return; }
        if (tieneTabla) {
          Array.prototype.forEach.call(el.childNodes, function (ch) { walk(ch, true); });
          return;
        }
        colocarBloque(el);
      }
      Array.prototype.forEach.call(Array.prototype.slice.call(folio.childNodes), function (n) { walk(n, false); });
      folio.remove();
      marcar(hojas);
      return hojas.length;
    }
    function marcar(hojas) {
      var total = hojas.length;
      hojas.forEach(function (h, i) {
        var s = document.createElement('div');
        s.className = 'preview-pagina';
        s.textContent = 'Página ' + (i + 1) + ' / ' + total;
        h.appendChild(s);
      });
    }
    window.__paginarListado = paginarListado;
    var nPaginas = 0;
    try { nPaginas = paginarListado(); } catch (e) { nPaginas = -1; }
    // #region agent log
    fetch('http://127.0.0.1:7584/ingest/0f1f3667-dd3e-47f6-820d-e79b40345fcc',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'0d3608'},body:JSON.stringify({sessionId:'0d3608',runId:'paginas',hypothesisId:'P',location:'previewDocumentoVentana.ts:paginarListado',message:'paginas preview',data:{paginar:PAGINAR,paginas:nPaginas},timestamp:Date.now()})}).catch(function(){});
    // #endregion
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
      var tipo = e.data && e.data.tipo;
      if (tipo !== '${PREVIEW_MSG_ERROR}' && tipo !== '${PREVIEW_MSG_RESULTADO}') return;
      var fallo = tipo === '${PREVIEW_MSG_ERROR}';
      err.style.color = fallo ? '#b91c1c' : '#15803d';
      err.textContent = String(e.data.mensaje || (fallo ? 'No se pudo imprimir' : 'Impresión enviada'));
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
