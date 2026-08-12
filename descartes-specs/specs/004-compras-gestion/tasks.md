---
description: "Task list for Módulo de Compras (Gestión)"
---

# Tasks: Módulo de Compras (Gestión)

**Input**: Design documents from `specs/004-compras-gestion/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md  
**Contract**: `contracts/compras-api.openapi.yaml` (esqueleto; completar al implementar)

**Tests**: No solicitados en spec — smoke manual al cerrar cada US.

**Organization**: Por user story (US1–US6). Estudio IA **fuera** de este listado.

**Monorepo root**: `c:\descartes-2.0\`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Paralelizable  
- **[USn]**: User story del spec.md

## Path Conventions

- **API**: `descartes-api/src/`
- **Gestión**: `descartes-gestion/src/`
- **Specs**: `descartes-specs/specs/004-compras-gestion/`
- **BD**: `db/script.sql` (sin migraciones MVP)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Carpetas y cableado mínimo del módulo Compras

- [X] T001 Crear `descartes-api/src/Routes/compras.php` (stub) y registrarlo en el bootstrap/`index.php` como ventas
- [X] T002 [P] Crear `descartes-api/src/Services/Compras/` y controllers bajo `Controllers/` (o `Http/Controllers/Compras/`)
- [X] T003 [P] Crear `descartes-gestion/src/views/compras/`, `descartes-gestion/src/api/compras.ts`, `descartes-gestion/src/types/compras.ts`
- [X] T004 [P] Crear `descartes-gestion/src/config/compras-nav.ts` con entradas: Albaranes de compra, Pedidos a proveedor, Facturas de proveedor

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Router, permisos, shell UI — bloquea todas las US

**⚠️ CRITICAL**: Completar antes de US1+

- [X] T005 Verificar módulo `compras` en `RolService::MODULOS` (ya listado) y sembrar permisos por defecto si hace falta en migración/roles
- [X] T006 Registrar rutas compras con `PermissionMiddleware` (`ver` GET; `crear`/`editar`/`eliminar` en escrituras)
- [X] T007 Quitar placeholder `compras` en `descartes-gestion/src/router/index.ts`; rutas hijas `/compras`, `/compras/albaranes`, … con guard `puede('compras','ver')`
- [X] T008 Integrar `compras-nav.ts` en layout/nav (mismo patrón que ventas)
- [X] T009 [P] Tipos TS alineados a data-model (`AlbaranCompraResumen`, `PedidoProveedor`, `FacturaCompra`)
- [X] T010 [P] Exponer contador `ultAlbaranDevCom` en mapeo tiendas (API + modal contadores) solo lectura
- [X] T011 Documentar sync XAMPP si aplica (`scripts/sync-xampp.ps1`)

**Checkpoint**: `/compras` accesible con `compras.ver`; API montada

---

## Phase 3: User Story 1 — Consultar albaranes de compra (Priority: P1) 🎯 MVP

**Goal**: Listado + detalle solo lectura con filtros

**Independent Test**: Localizar albarán por proveedor/fecha en ≤3 acciones; ver cabecera y líneas

- [X] T012 [US1] `AlbaranCompraConsultaService.php` — listado paginado `AlbaranesCompraCab` + detalle con `AlbaranesComprasLin`; filtros fecha, empresa, proveedor, almacén, albaran/suAlbaran
- [X] T013 [US1] Controller + `GET /api/compras/albaranes` y `GET /api/compras/albaranes/{empresa}/{albaran}`
- [X] T014 [P] [US1] Cliente `listarAlbaranesCompra` / `obtenerAlbaranCompra` en `api/compras.ts`
- [X] T015 [US1] `ComprasAlbaranesListView.vue` — filtros + rejilla
- [X] T016 [US1] `CompraAlbaranDetalleView.vue` — cabecera + líneas (modo lectura si no editable)
- [X] T017 [US1] Rutas listado/detalle en router + nav      
- [X] T018 [US1] Smoke SC-001

**Checkpoint**: Consulta albaranes usable

**Smoke SC-001 (manual)**: Desde menú Compras → Albaranes (1), filtrar proveedor + Buscar con fechas del día (2), abrir albarán (3). Fechas default = hoy; tienda del puesto. Requiere API sincronizada (XAMPP) + permiso `compras.ver` + datos de prueba.
---

## Phase 4: User Story 2 — Alta y edición de albarán (Priority: P1)

**Goal**: Crear/editar albaranes no bloqueados; contador `UltAlbaranCom`

**Independent Test**: Alta con 2 líneas; reabrir; editar cantidad; bloquear si `TrasCtb`/`Actualizado`

- [X] T019 [US2] `AlbaranCompraEscrituraService.php` — create/update; incrementar `UltAlbaranCom`; recalcular importes cabecera; validar proveedor/artículos/`BloqueoCompra`
- [X] T020 [US2] Reglas bloqueo: denegar edición si `TrasCtb=1` o `Actualizado=1` (mensaje claro)
- [X] T021 [US2] `POST /api/compras/albaranes`, `PUT .../{empresa}/{albaran}`, `DELETE ...` (solo si Actualizado=0 y TrasCtb=0)
- [X] T022 [P] [US2] Cliente API create/update/delete
- [X] T023 [US2] UI ficha: toolbar Nuevo/Modificar/Guardar/Borrar; búsqueda proveedor/artículo; flag devolución
- [X] T024 [US2] Acción **Actualizar stock** (`POST .../actualizar-stock`): upsert `Stock.Entradas` (+ valor); `Actualizado=1`; transacción (R-004)
- [X] T025 [US2] Smoke SC-002 + caso albarán ya actualizado no editable

**Checkpoint**: Alta/edición + entrada de stock

**Smoke SC-002 (manual / API)**: Compras → Albaranes → Nuevo; cabecera con proveedor + almacén; 5 líneas de artículo; Guardar (sin error). Reabrir, Modificar cantidad, Guardar. **Actualizar stock** → `Actualizado=1` y ficha solo lectura. Intentar Modificar/Guardar debe denegarse (mensaje Actualizado). Requiere API sincronizada + `compras.crear`/`editar`. Verificado API 2026-08-12: albarán `1/26000004`.
---

## Phase 5: User Story 3 — Pedidos a proveedor (Priority: P2)

**Goal**: Listado, alta y detalle de `PedidosCab`/`PedidosLin`

- [X] T026 [US3] `PedidoProveedorConsultaService` + `PedidoProveedorEscrituraService` (contador `UltPedidoCom`)
- [X] T027 [US3] Endpoints GET list/detail, POST create, PUT update (abiertos)
- [X] T028 [P] [US3] Cliente API + tipos
- [X] T029 [US3] `ComprasPedidosListView.vue` + `CompraPedidoDetalleView.vue` (cant. pedida/servida, situación derivada)
- [X] T030 [US3] Rutas `/compras/pedidos` y detalle

**Checkpoint**: Pedidos consultables y creables
---

## Phase 6: User Story 4 — Recepción pedido → albarán (Priority: P2)

**Goal**: Generar albarán desde pedido; actualizar `CantidadSer`

- [X] T031 [US4] `POST /api/compras/pedidos/{empresa}/{pedido}/recibir` — body cantidades por línea; crear albarán; link `Pedido`; incrementar `CantidadSer`; situación
- [X] T032 [US4] Validar no superar pendiente; pedido ya servido → error

**Validación recepción (T032)**: `situacionLabel=servido` → 409; cantidad > pendiente o línea sin pendiente → 400. Verificado en smoke T031 (pedido `1/26000004`).
- [X] T033 [US4] UI botón «Recibir» / modal cantidades en ficha pedido; navegar al albarán creado
- [X] T034 [US4] Smoke SC-003 (parcial + resto)

**Checkpoint**: Flujo pedido→albarán

**Smoke SC-003 (manual / API)**: Pedido con 10 uds → Recibir 4 → albarán con 4 y `CantidadSer=4` (parcial) → segunda recepción del resto (6) → `CantidadSer=10` y situación servido. UI: ficha pedido → Recibir → modal → navega al albarán. Requiere `compras.editar`. Verificado API 2026-08-12.
---

## Phase 7: User Story 5 — Facturas proveedor consulta (Priority: P3)

**Goal**: Listado + detalle solo lectura `FacturasCompras`

- [X] T035 [US5] Servicio consulta + `GET /api/compras/facturas` y `GET .../facturas/{factura}`
- [X] T036 [P] [US5] Cliente API
- [X] T037 [US5] `ComprasFacturasListView.vue` + detalle (bases, IVA, vencimientos)
- [X] T038 [US5] Rutas nav Facturas

**Checkpoint**: Consulta facturas
---

## Phase 8: User Story 6 — Impresión A4 (Priority: P3)

**Goal**: Preview + print con plantilla/impresora del puesto

- [X] T039 [US6] Reutilizar composable impresión A4 de ventas (o extraer compartido) con tipo albarán-compras / pedido-compras
- [X] T040 [US6] Botón Imprimir en ficha albarán compra y pedido; resolver `formatoAlbaranCompras` / `formatoPedidoCompras` + `imp*`
- [X] T041 [US6] Smoke SC-005 (impresora correcta, no tickets)

**Checkpoint**: Impresión A4 compras

**Smoke SC-005**: En ficha albarán compra → Imprimir → preview A4; impresora/plantilla del puesto = Generales II «Albaranes compras» (`formatoAlbaranCompras` / `impAlbaranCompras` / `impresoraAlbaranCompras`), **no** térmica de tickets. Pedido usa «Pedidos compras». Verificado 2026-08-12: meta código + puesto `01` (`AlbaranCompra.rpt` / `int to PDF`).
---

## Phase 9: Polish

- [X] T042 [P] Mensajes de error API homogéneos (`extractApiError`)
- [X] T043 [P] Completar `contracts/compras-api.openapi.yaml` con endpoints reales
- [X] T044 Quickstart corto en `specs/004-compras-gestion/quickstart.md` (permisos, contadores, probar stock)
- [X] T045 Revisar que estudio IA **no** tiene tareas de código pendientes

**Verificación T045 (2026-08-12)**: Spec «Estudio (no MVP)» + R-012 — sin implementación en v1. `tasks.md` T001–T044 solo MVP (sin OCR/IA). No existe `research-importacion-documento-proveedor.md` (entregable futuro). Sin stubs en API/UI compras. `plan.md` deja el research IA como *(más adelante)*.

---

## Dependencies (orden)

```
Phase 1 → Phase 2 → Phase 3 (US1) → Phase 4 (US2)
                                  ↘ Phase 5 (US3) → Phase 6 (US4)
Phase 3/4 → Phase 8 (US6) parcial
Phase 7 (US5) tras Phase 2 (independiente de US2)
Phase 9 al final
```

## MVP sugerido para primer demo

**Phases 1–4** (shell + consulta + alta/edición albarán + actualizar stock).  
Pedidos/recepción/facturas/impresión en sprints siguientes.
