# Feature Specification: Creación e impresión de etiquetas (Gestión)

**Feature Branch**: `005-etiquetas-gestion`  
**Created**: 2026-08-13  
**Updated**: 2026-08-13 (decisiones negocio)  
**Status**: Draft  
**Input**: User description: "Siguiente plan: creación e impresión de etiquetas. Nuevo spec."

## Contexto

En Descartes 1.0 las etiquetas de artículo (precio / código de barras) se gestionan con tablas legacy en SQL Server y la impresora de etiquetas del puesto. En Gestión 2.0 ya existen:

| Pieza 2.0 | Estado |
|-----------|--------|
| Botón **Etiquetas** en ficha Artículo | Placeholder deshabilitado («pendiente del módulo») |
| Puesto → `ImpresoraEtiquetas` | Configurado en Mantenimiento |
| Tienda → flags `ImpEtiquetasSinEans`, `EtiquetasIvaIncluido`, `ImpEtiquetasSoloEansPropios` | Ya mapeados |
| Artículo → `EtiquetaPrecio`, EAN (`ArtBarras`) | Ya en mantenimiento |
| Bridge Electron | `printHtml` (A4) operativo; **`printLabel` stub** pendiente |
| Plantillas documentos | Diseñador editable (A4 / ticket) — **extender a tipo etiqueta** |

Tablas legacy relevantes (Principio IV):

| Concepto | Tabla |
|----------|--------|
| Cola / líneas a imprimir | `EtiquetasArticulo` |
| Formatos legacy (referencia / migración) | `FormatosEtiquetas` |
| Copias | campo `Etiquetas` / `Cantidad` en cola |

Este spec define el **MVP de Gestión**: cola de etiquetas + impresión directa desde artículo, con **plantillas editables** e impresora `ImpresoraEtiquetas` del puesto.

**Fuera de alcance v1**:

- Etiquetas de **envío**
- Etiquetas electrónicas ESL
- Motor ZPL/EPL nativo (salvo hardware concreto más adelante; ver R-001)
- Offline TPV

---

## Decisiones cerradas (2026-08-13)

| # | Tema | Decisión |
|---|------|----------|
| 1 | Motor de impresión | **Plantilla HTML editable → render → Electron `printHtml` / `printLabel`** hacia la impresora Windows del puesto. Ver [research R-001](./research.md). |
| 2 | Formatos | **Plantillas editables** (mismo ecosistema que Documentos), tipo `etiqueta`, tamaño de papel configurable (mm). `FormatosEtiquetas` legacy solo como referencia/migración opcional. |
| 3 | Tras imprimir | **Vaciar de la cola** las líneas impresas correctamente. El nº de veces se controla con el campo **cantidad/copias** de cada línea (≥ 1). |
| 4 | Navegación | **Menú propio «Etiquetas»** (cola: alta, editar copias, imprimir lote). El botón en ficha **Artículo** es **solo impresión rápida** (dialog copias → imprimir), sin sustituir la cola. |
| 5 | Desde albarán compra | **Sí, en MVP (P2)**: botón en albarán de compra que vuelca líneas a la cola (copias ≈ cantidad). |

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Impresión rápida desde ficha de artículo (Priority: P1)

Un usuario en Mantenimiento → Artículos abre un artículo y pulsa **Etiquetas**. Indica copias, elige EAN si hay varios, ve preview breve y **imprime ya** en la impresora de etiquetas del puesto (no gestiona la cola completa aquí).

**Why this priority**: Caso diario (reponer / cambiar precio); el botón ya existe en UI.

**Independent Test**: Artículo con EAN; Etiquetas → 2 copias → Imprimir → 2 etiquetas en la impresora del puesto.

**Acceptance Scenarios**:

1. **Given** artículo con EAN y permiso, **When** abre Etiquetas desde la ficha, **Then** puede elegir EAN (si varios) y copias (≥ 1) e imprimir.
2. **Given** puesto con `ImpresoraEtiquetas`, **When** confirma, **Then** imprime ahí (no tickets / no A4 documentos).
3. **Given** sin EAN y tienda no permite sin EAN, **When** intenta imprimir, **Then** error claro y no imprime.
4. **Given** sin permiso, **When** pulsa Etiquetas, **Then** acceso denegado.

---

### User Story 2 - Menú Etiquetas: cola e impresión masiva (Priority: P1)

Entrada de menú **Etiquetas**: listado/cola (`EtiquetasArticulo`). El usuario añade artículos (código / EAN / escáner), edita **cantidad (copias)**, borra líneas, preview e **imprime todas o selección**. Tras impresión OK, **esas líneas se eliminan de la cola**.

**Why this priority**: Flujo de lote; el menú es el sitio para “crear” trabajo de etiquetas.

**Independent Test**: Añadir 3 artículos con copias 1, 2 y 5; imprimir todo; cola vacía; papel coherente.

**Acceptance Scenarios**:

1. **Given** permiso `etiquetas.ver`, **When** abre menú Etiquetas, **Then** ve la cola (artículo, descripción, EAN, precio, cantidad).
2. **Given** permiso crear/editar, **When** añade por código/EAN/escáner con cantidad N, **Then** la línea persiste en cola.
3. **Given** líneas en cola, **When** imprime selección/todas con éxito, **Then** se respetan cantidades y **se vacían** las líneas impresas.
4. **Given** fallo parcial de impresora, **When** termina el lote, **Then** no se borran las no impresas (o se informa y se confirman).
5. **Given** una línea, **When** la elimina manualmente, **Then** desaparece de cola/BD.

---

### User Story 3 - Plantillas editables de etiqueta (Priority: P1)

En Configuración / Documentos (o sección Etiquetas), el usuario edita plantillas de tipo **etiqueta**: tamaño en mm, bloques (texto, campos artículo, código de barras EAN, precio). El puesto o la tienda asocia qué plantilla usar al imprimir.

**Why this priority**: Decisión explícita: no depender solo de formatos Crystal/legacy opacos.

**Independent Test**: Crear/editar plantilla; preview con datos de un artículo; imprimir y ver el layout.

**Acceptance Scenarios**:

1. **Given** permiso de configuración de plantillas, **When** crea plantilla tipo etiqueta, **Then** puede definir tamaño (mm) y bloques enlazados a datos de artículo/EAN/precio.
2. **Given** plantilla activa, **When** imprime desde ficha o cola, **Then** se usa esa plantilla.
3. **Given** preview, **When** cambia un bloque y guarda, **Then** la siguiente impresión refleja el cambio.

---

### User Story 4 - Preview antes de imprimir (Priority: P2)

Preview WYSIWYG/simple de la etiqueta (mismos datos que se imprimirán).

**Independent Test**: Preview = mismos código, descripción, EAN y precio que el trabajo de impresión.

**Acceptance Scenarios**:

1. **Given** línea o diálogo de ficha, **When** pide preview, **Then** ve descripción, código, barras EAN y precio.
2. **Given** preview OK, **When** imprime, **Then** datos coinciden con el preview.

---

### User Story 5 - Generar desde albarán de compra (Priority: P2)

Tras recibir mercancía, desde un **albarán de compra** el usuario pulsa «Generar etiquetas»: se añaden a la cola `EtiquetasArticulo` una línea por artículo (EAN preferente, copias sugeridas desde cantidad de línea, enlace `Empresa`/`Albaran`). Luego puede ajustar cantidades e imprimir desde el menú Etiquetas.

**Why this priority**: Atajo post-recepción; depende de cola (US2) y de Compras (004).

**Independent Test**: Albarán con 2 líneas → Generar etiquetas → cola con 2 entradas vinculadas al albarán.

**Acceptance Scenarios**:

1. **Given** albarán de compra con líneas de artículo, **When** genera etiquetas, **Then** se crean filas en cola con artículo, descripción, EAN y copias derivadas de cantidad (editables).
2. **Given** tienda «solo EAN propios», **When** una línea no tiene EAN, **Then** se omite o se avisa según `ImpEtiquetasSoloEansPropios`.
3. **Given** generación OK, **When** el usuario abre menú Etiquetas, **Then** ve las líneas nuevas y puede imprimir (vaciar tras OK).

---

### Edge Cases

- Artículo sin EAN y tienda que no permite etiquetas sin EAN.
- Varios EAN: elegir en UI; default = primer EAN o el de `Unidades = 1`.
- Precio 0 / ofertas: default `PrecioVen1` (o tarifa) + `EtiquetasIvaIncluido` (research).
- Puesto sin `ImpresoraEtiquetas`: mensaje claro.
- Escáner en cola: `articulos/resolver`.
- Fallo parcial: no vaciar líneas no impresas.
- Descripción larga: truncar según plantilla.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: MUST permitir crear (cola) e imprimir etiquetas de artículo: código, descripción, EAN, precio, **cantidad/copias**.
- **FR-002**: MUST usar `ImpresoraEtiquetas` del puesto.
- **FR-003**: MUST persistir la cola en `EtiquetasArticulo`.
- **FR-004**: MUST respetar flags de tienda (`ImpEtiquetasSinEans`, `ImpEtiquetasSoloEansPropios`, `EtiquetasIvaIncluido`).
- **FR-005**: MUST reutilizar `GET .../articulos/resolver` al añadir a cola.
- **FR-006**: MUST permisos módulo `etiquetas` (`ver` / `crear` / `editar` / `eliminar`); imprimir requiere `editar` (o documentar acción en plan).
- **FR-007**: MUST menú **Etiquetas** (cola). MUST botón Artículo = **solo impresión rápida**.
- **FR-008**: Tras impresión correcta, MUST **eliminar de la cola** las líneas impresas. La repetición se controla con **cantidad ≥ 1** antes de imprimir.
- **FR-009**: MUST plantillas **editables** tipo etiqueta (diseñador), no solo lectura de `FormatosEtiquetas`.
- **FR-010**: MUST imprimir vía bridge Electron: implementar `printLabel` (o `printHtml` con `pageSize` en mm de la plantilla). En navegador sin Electron: preview + diálogo de impresión del sistema como fallback.
- **FR-011**: MUST preview (al menos en menú cola; recomendable también en impresión rápida).
- **FR-012**: API REST `/api/etiquetas/...` + auth/permisos.
- **FR-013**: MUST permitir generar cola desde albarán de compra (US5), enlazando `Empresa` + `Albaran`.

### Key Entities

- **EtiquetaEnCola (`EtiquetasArticulo`)**: artículo, EAN, cantidad/copias, precio, descripción, puesto, origen opcional.
- **PlantillaEtiqueta**: plantilla editable (tipo `etiqueta`, tamaño mm, bloques).
- **Artículo / ArtBarras**: datos y EAN.
- **Puesto**: `ImpresoraEtiquetas` (+ asociación a plantilla si aplica).
- **Tienda**: políticas de impresión.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Desde ficha artículo, imprimir ≥ 1 etiqueta correcta en ≤ 5 acciones.
- **SC-002**: Cola con ≥ 10 líneas: un lote respeta cantidades y **deja la cola vacía** de lo impreso con éxito.
- **SC-003**: 100 % de trabajos usan `ImpresoraEtiquetas` si está configurada (smoke).
- **SC-004**: Sin EAN / sin impresora → error claro; no usa impresora de tickets.
- **SC-005**: Usuario puede editar una plantilla de etiqueta y ver el cambio en preview/impresión en la misma sesión.
- **SC-006**: Desde albarán de compra con ≥ 2 líneas, generar etiquetas crea ≥ 2 filas en cola en una sola operación.

---

## Assumptions

- Gestión online (API + red).
- Impresora de etiquetas instalada como impresora Windows (driver del fabricante).
- Precio por defecto = venta habitual (`PrecioVen1` / tarifa) + flag IVA incluido de tienda.

---

## Next artifacts

1. [x] Decisiones en spec (incl. US5 albarán compra en MVP)  
2. [x] `research.md`  
3. [x] `data-model.md`  
4. [x] `contracts/etiquetas-api.openapi.yaml`  
5. [x] `tasks.md`  
6. [ ] `quickstart.md` al cerrar (T036)  
