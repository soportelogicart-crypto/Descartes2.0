---
description: "Task list for Creación e impresión de etiquetas (Gestión)"
---

# Tasks: Creación e impresión de etiquetas (Gestión)

**Input**: Design documents from `specs/005-etiquetas-gestion/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md  
**Contract**: `contracts/etiquetas-api.openapi.yaml` (esqueleto; completar al implementar)

**Tests**: Smoke manual al cerrar cada US.

**Organization**: Por user story (US1–US5).

**Monorepo root**: `c:\descartes-2.0\`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Paralelizable  
- **[USn]**: User story del spec.md

## Path Conventions

- **API**: `descartes-api/src/`
- **Gestión**: `descartes-gestion/src/`
- **Electron**: `descartes-electron/electron/`
- **Specs**: `descartes-specs/specs/005-etiquetas-gestion/`

---

## Phase 1: Setup

**Purpose**: Carpetas y cableado mínimo

- [X] T001 Crear `descartes-api/src/Routes/etiquetas.php` (stub) y registrarlo en bootstrap/`index.php`
- [X] T002 [P] Crear `descartes-api/src/Services/Etiquetas/` + `Controllers/EtiquetasController.php`
- [X] T003 [P] Crear `descartes-gestion/src/views/etiquetas/`, `api/etiquetas.ts`, `types/etiquetas.ts`
- [X] T004 [P] Crear `descartes-gestion/src/config/etiquetas-nav.ts` (entrada menú Etiquetas)

---

## Phase 2: Foundational

**Purpose**: Permisos, router, nav — bloquea US

- [X] T005 Añadir módulo `etiquetas` a `RolService::MODULOS` + migración/semilla permisos ADMIN
- [X] T006 Registrar rutas `/api/etiquetas` con `PermissionMiddleware` (ver/crear/editar/eliminar)
- [X] T007 Rutas Vue `/etiquetas` con guard `puede('etiquetas','ver')` + integrar nav
- [X] T008 [P] Tipos TS según data-model (`EtiquetaColaLinea`, payloads)
- [X] T009 Documentar sync XAMPP si aplica

**Checkpoint**: `/etiquetas` accesible; API ping OK

---

## Phase 3: User Story 2 — Cola (Priority: P1) 🎯 MVP

**Goal**: CRUD cola `EtiquetasArticulo` + cantidad/copias

- [X] T010 [US2] `EtiquetaColaService` — listar / crear / actualizar / borrar sobre `EtiquetasArticulo`
- [X] T011 [US2] Endpoints GET/POST `/api/etiquetas`, PUT/DELETE `.../{articulo}/{nroLin}`
- [X] T012 [P] [US2] Cliente API cola
- [X] T013 [US2] `EtiquetasColaView.vue` — rejilla, añadir (código/EAN/escáner vía resolver), editar cantidad, borrar
- [X] T014 [US2] Smoke SC-cola: 3 líneas con cantidades distintas persisten

**Smoke SC-cola (API)**: `php descartes-api/scripts/smoke-etiquetas-cola.php` — crea 3 líneas (cantidades 1/3/5), verifica `listar`, actualiza 1→7, limpia. Verificado 2026-08-13. Manual UI: Etiquetas → añadir 3 artículos con copias distintas → Actualizar → editar cantidad → Quitar.

**Checkpoint**: Cola usable sin imprimir aún

---

## Phase 4: User Story 3 — Plantillas editables (Priority: P1)

**Goal**: Tipo `etiqueta` en diseñador + tamaño mm

- [X] T015 [US3] Extender `DocumentoTipo` / page format `label` + `widthMm`/`heightMm` en types plantillas
- [X] T016 [US3] Plantilla estándar etiqueta (codigo, descripcion, ean barras, precio)
- [X] T017 [US3] UI diseñador / listado plantillas acepta tipo etiqueta
- [X] T018 [US3] Asociación plantilla activa (puesto o config global v1) documentada e implementada

**Asociación multi-formato (T018)**: N plantillas `etiqueta` (tamaños distintos) + `Puestos.FormatoEtiquetas` (default puesto, mig. `008`) + `Activa` empresa + resolver `usePlantillaEtiqueta.ts`. Selector al imprimir en Phase 5.

**Checkpoint**: Preview de plantilla con datos de ejemplo

---

## Phase 5: Impresión + vaciar cola (US2/US4) (Priority: P1)

**Goal**: Render HTML → Electron → vaciar OK

- [X] T019 Implementar `printLabel` en `descartes-electron` (pageSize mm; reutilizar `printHtml`)
- [X] T020 [P] Exponer tipado `printLabel` en bridge gestión
- [X] T021 [US2][US4] Composable render etiqueta desde plantilla + datos artículo/cola
- [X] T022 [US2] Acción Imprimir (todas/selección): N copias por línea; impresora `ImpresoraEtiquetas`
- [X] T023 [US2] `POST /api/etiquetas/imprimir` — DELETE líneas confirmadas OK
- [X] T024 [US4] Preview modal antes de imprimir
- [X] T025 Smoke SC-002: imprimir lote → cola vacía de lo impreso; impresora correcta

**Smoke SC-002 (API)**: `php descartes-api/scripts/smoke-etiquetas-imprimir.php` — 10 líneas (cantidades 1–5), confirmar parcial 7 + resto 3 → cola vacía de lo impreso; lee `Puestos.ImpresoraEtiquetas`. Verificado 2026-08-13. Manual UI: Etiquetas → lote → Preview → Imprimir → cola vacía; salida por ImpresoraEtiquetas del puesto.

**Checkpoint**: Lote imprimible y cola se vacía

---

## Phase 6: User Story 1 — Impresión rápida ficha artículo (Priority: P1)

**Goal**: Botón Etiquetas en Artículo = solo imprimir

- [X] T026 [US1] Dialog impresión rápida (EAN, cantidad, preview, imprimir)
- [X] T027 [US1] Habilitar botón toolbar Artículo (quitar disabled/placeholder)
- [X] T028 [US1] Respetar flags tienda sin EAN / solo EAN propios
- [X] T029 [US1] Smoke SC-001: desde ficha → 2 copias → impresora etiquetas

**Smoke SC-001 (API)**: `php descartes-api/scripts/smoke-etiquetas-rapida.php` — puesto con `ImpresoraEtiquetas`, artículo+EAN ArtBarras, flags tienda (T028), copias=2. Verificado 2026-08-13. Manual UI (≤5): Artículos → Etiquetas → EAN → Copias 2 → Imprimir.

**Checkpoint**: Impresión rápida operativa

---

## Phase 7: User Story 5 — Desde albarán compra (Priority: P2)

**Goal**: Volcar líneas de albarán a cola

- [X] T030 [US5] `POST /api/etiquetas/desde-albaran-compra` (empresa, albaran → insert cola)
- [X] T031 [P] [US5] Cliente API
- [X] T032 [US5] Botón «Generar etiquetas» en `CompraAlbaranDetalleView`
- [X] T033 [US5] Smoke SC-006: albarán 2 líneas → cola ≥ 2; luego imprimir

**Smoke SC-006 (API)**: `php descartes-api/scripts/smoke-etiquetas-albaran.php` — albarán ≥2 líneas → encolar ≥2 → listar → confirmar impresión → cola vacía. Verificado 2026-08-13. Manual UI: Albarán → Generar etiquetas → Etiquetas → Imprimir.

**Checkpoint**: Flujo recepción → etiquetas

---

## Phase 8: Polish

- [X] T034 [P] Completar `contracts/etiquetas-api.openapi.yaml` con schemas reales
- [X] T035 Mensajes error homogéneos (`extractApiError`)
- [X] T036 Quickstart `quickstart.md` (permisos, puesto impresora, plantilla, smokes)
- [X] T037 Sync XAMPP + verificar stub `printLabel` no stub en Electron build local

**T037**: Sync XAMPP OK (2026-08-13). `printLabel` en `peripherals.js` usa `printHtmlSized` con `stub: false` (no stub). Reiniciar Electron tras cambios al bridge.
---

## Dependencies

```
Phase 1 → Phase 2 → Phase 3 (cola)
                  → Phase 4 (plantillas) → Phase 5 (imprimir)
Phase 3+5 → Phase 6 (ficha artículo)
Phase 3 → Phase 7 (albarán compra)
Phase 8 al final
```

## MVP primer demo

**Phases 1–6** (cola + plantillas + imprimir + ficha).  
Phase 7 (albarán) en el mismo sprint si hay tiempo; si no, inmediatamente después.
