# Research: 005-etiquetas-gestion

**Feature**: Creación e impresión de etiquetas  
**Date**: 2026-08-13  
**Spec**: [spec.md](./spec.md)

## R-001 — Motor de impresión (decisión)

### Alternativas

| Opción | Pros | Contras |
|--------|------|---------|
| **A. HTML + Electron `printHtml` / `printLabel`** | Ya existe bridge; plantillas editables visuales; funciona con drivers Windows de la mayoría de impresoras de etiquetas; mismo patrón que A4 | Hay que pasar `pageSize` en mm (hoy A4 hardcodeado); calidad depende del driver |
| B. ZPL/EPL raw | Ideal en Zebra “puras”; control preciso | Plantillas poco editables para usuario de oficina; lenguaje por fabricante; más código |
| C. Solo Crystal/`FormatosEtiquetas` legacy | Compatibilidad 1.0 | No editable en 2.0; fuera de estrategia de plantillas |

### Decisión: **A**

1. Extender plantillas documento con `tipo: 'etiqueta'` y `page.format` tipo `label` + `widthMm` / `heightMm`.
2. Render HTML (código, descripción, precio, SVG/canvas de código de barras EAN).
3. Implementar stub `printLabel` en `descartes-electron` como impresión silenciosa a `ImpresoraEtiquetas` con tamaño de página de la plantilla (reutilizar lógica de `printHtml`, parametrizando `pageSize`).
4. Fallback navegador: `window.print()` / diálogo tras preview.

**Rationale**: El usuario pidió plantillas editables; el monorepo ya tiene diseñador + `printHtml` + stub `printLabel`. Evita ZPL hasta que un hardware concreto lo exija.

---

## R-002 — UX: menú vs botón artículo

- **Menú Etiquetas**: cola (`EtiquetasArticulo`) — crear líneas, cantidad, imprimir lote, vaciar tras OK.
- **Ficha Artículo → Etiquetas**: impresión rápida (copias → imprimir), sin sustituir la cola.

---

## R-003 — Cola tras imprimir

- Campo **cantidad/copias** = cuántas etiquetas físicas de esa línea.
- Tras impresión correcta → **DELETE** de esas filas en `EtiquetasArticulo`.
- Si falla una parte del lote → no borrar las no impresas (o confirmar).

---

## R-004 — Legacy a muestrear en implementación

- `EtiquetasArticulo`: columnas Articulo, Ean, Etiquetas/Cantidad, Precio, Descripcion, Puesto, Empresa, Albaran, Lote…
- `FormatosEtiquetas`: solo referencia; no es el diseñador 2.0.
- Flags tienda ya en API: `impEtiquetasSinEans`, `etiquetasIvaIncluido`, `impEtiquetasSoloEansPropios`.

---

## R-005 — Generar desde albarán de compra (MVP P2)

- Botón en ficha albarán compra → inserta en `EtiquetasArticulo` (Articulo, Ean, Etiquetas/Cantidad, Descripcion, Precio, Empresa, Albaran, TipoDocumento, Proveedor, FechaDocumento, Lote si hay).
- Copias sugeridas = cantidad de línea (redondeo a entero ≥ 1); usuario puede editar en cola antes de imprimir.
- Respeta flags de tienda sobre EAN.
- Requiere US2 (cola) operativa.
