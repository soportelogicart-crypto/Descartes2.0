---
description: "Task list for M�dulo de Ventas (Gesti�n)"
---

# Tasks: M�dulo de Ventas (Gesti�n)

**Input**: Design documents from `specs/002-ventas-gestion/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ventas-api.openapi.yaml

**Tests**: No solicitados en spec � tareas de test omitidas (salvo smoke manual en Polish v�a quickstart.md).

**Organization**: Por user story (US1�US8). US8 (Registradora) se implementa como filtro de puesto en US3, sin submen� aparte.

**Monorepo root**: `c:\descartes-2.0\`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Paralelizable (archivos distintos, sin dependencias pendientes)
- **[USn]**: User story del spec.md

## Path Conventions

- **API**: `descartes-api/src/`, `descartes-api/public/`, `descartes-api/tests/`
- **Gesti�n**: `descartes-gestion/src/`
- **Specs**: `descartes-specs/specs/002-ventas-gestion/`
- **BD**: `db/script.sql` (solo lectura; sin migraciones en MVP)

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Estructura de carpetas y cableado m�nimo del m�dulo Ventas

- [X] T001 Crear carpeta `descartes-api/src/Routes/ventas.php` y stub de registro vac�o listo para montar en `descartes-api/public/index.php`
- [X] T002 [P] Crear carpetas `descartes-api/src/Services/Ventas/` y `descartes-api/src/Http/Controllers/Ventas/` (o Controllers/Ventas seg�n convenci�n existente del repo)
- [X] T003 [P] Crear carpeta `descartes-gestion/src/views/ventas/` y `descartes-gestion/src/api/ventas.ts` (cliente HTTP vac�o con base path `/api/ventas`)
- [X] T004 [P] Crear `descartes-gestion/src/config/ventas-nav.ts` con submen�s: Ventas, Arqueo de caja, Desglose de arqueo, Diario de anulaciones, Cobros y Pagos, Liquidaci�n de Vales, Pedido de Clientes (sin entrada �Registradora�)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Prerrequisitos que bloquean todas las user stories

**?? CRITICAL**: Ninguna user story hasta completar esta fase

- [X] T005 Extender mapeo `formas-pago` en `descartes-api/src/Config/entities.php` con campos `cobroDeArqueo` ? `CobroDeArqueo` y `cobroPago` ? `CobroPago` (boolean / string 1 char)
- [X] T006 [P] Exponer `cobroDeArqueo` y `cobroPago` en UI de formas de pago (`descartes-gestion/src/config/entidades.ts` y/o ficha/grid de formas-pago) para poder configurar �cuenta para arqueo� y cobro/pago
- [X] T007 Registrar carga de rutas ventas en `descartes-api/public/index.php` (require `Routes/ventas.php`) con mismos middlewares de sesi�n/auth/permiso que mantenimiento
- [X] T008 Implementar helper de permiso m�dulo `ventas` en rutas (PermissionMiddleware: `ver` en GET; `crear`/`editar` en escrituras) reutilizando `descartes-api/src/Middleware/PermissionMiddleware.php`
- [X] T009 Crear layout/router shell Ventas: quitar `ventas` de `modulosPlaceholder` en `descartes-gestion/src/router/index.ts` y a�adir rutas hijas bajo `/ventas` con guard de `puede('ventas','ver')`
- [X] T010 Integrar submen� lateral/top desde `ventas-nav.ts` en `descartes-gestion/src/components/layout/AppLayout.vue` (o componente nav de m�dulo) respetando permiso `ventas.ver`
- [X] T011 [P] A�adir tipos TypeScript compartidos de Ventas en `descartes-gestion/src/types/ventas.ts` (VentaResumen, Arqueo, Anulacion, Vale, Pedido) alineados a `contracts/ventas-api.openapi.yaml`
- [X] T012 Documentar en comentario o README corto de sync: copiar cambios API a `C:\xampp\htdocs\descartes-api\` si el runtime usa esa copia (Principio IX)

**Checkpoint**: Formas de pago con flags; shell `/ventas` accesible solo con permiso ver; rutas API montadas

---

## Phase 3: User Story 1 � Consultar ventas realizadas (Priority: P1) ?? MVP

**Goal**: Listado y detalle de ventas TPV (solo lectura) con filtros fecha/puesto/vendedor/cliente/estado

**Independent Test**: Usuario con `ventas.ver` localiza una venta del d�a por cliente, puesto o rango horario en ?3 acciones y ve cabecera + l�neas; no puede editar importes

### Implementation for User Story 1

- [X] T013 [US1] Implementar `descartes-api/src/Services/Ventas/VentaConsultaService.php` (listado paginado sobre `AlbaranesVentasCab` + detalle con `AlbaranesVentasLin`, filtros fecha/puesto/vendedor/cliente/estado)
- [X] T014 [US1] Implementar `descartes-api/src/Http/Controllers/Ventas/VentaController.php` con `GET /api/ventas/albaranes` y `GET /api/ventas/albaranes/{empresa}/{tipo}/{albaran}` seg�n `contracts/ventas-api.openapi.yaml`
- [X] T015 [US1] Registrar endpoints de T014 en `descartes-api/src/Routes/ventas.php` (solo permiso `ver`; sin POST/PUT de importes)
- [X] T016 [P] [US1] A�adir funciones `listarVentas` / `obtenerVenta` en `descartes-gestion/src/api/ventas.ts`
- [X] T017 [US1] Crear `descartes-gestion/src/views/ventas/VentasListView.vue` con filtros (fecha, tienda/puesto, vendedor, cliente, estado) y rejilla de resultados
- [X] T018 [US1] Crear `descartes-gestion/src/views/ventas/VentaDetalleView.vue` (o panel detalle) mostrando IVA por tipo, formas de pago, facturada/n� factura y l�neas (art�culo, cantidad, precio, descuentos) en solo lectura
- [X] T019 [US1] Enlazar rutas `/ventas` (listado) y `/ventas/:empresa/:tipo/:albaran` (detalle) en `descartes-gestion/src/router/index.ts`
- [X] T020 [US1] Verificar UX SC-001: desde submen� Ventas, aplicar filtro d�a actual + cliente/puesto/hora en ?3 acciones de UI

**Checkpoint**: MVP consultable � listado + detalle ventas sin escritura

---

## Phase 4: User Story 2 � Cuadrar el arqueo de caja (Priority: P1)

**Goal**: Consulta de arqueo por sesi�n y puesto; total cuadra con formas `CobroDeArqueo`

**Independent Test**: Para sesi�n/puesto conocidos, `totalArqueo` = suma de filas `Arqueo` cuya forma de pago tiene `CobroDeArqueo=1`

### Implementation for User Story 2

- [X] T021 [US2] Implementar `descartes-api/src/Services/Ventas/ArqueoService.php` (leer `Arqueo` + join `FormasPago`; calcular total solo `CobroDeArqueo`; incluir `Entrado`/`Acumulado`; metadatos de `Sesiones` si �til)
- [X] T022 [US2] Implementar `GET /api/ventas/arqueos` en controller + `descartes-api/src/Routes/ventas.php` (query empresa, puesto, sesion)
- [X] T023 [P] [US2] A�adir `obtenerArqueo` en `descartes-gestion/src/api/ventas.ts`
- [X] T024 [US2] Crear `descartes-gestion/src/views/ventas/ArqueoView.vue` (selector empresa/puesto/sesi�n; tabla por forma de pago; total arqueo)
- [X] T025 [US2] Ruta `/ventas/arqueo` en `descartes-gestion/src/router/index.ts` y entrada nav ya definida en T004
- [X] T026 [US2] Validar SC-002 manualmente con datos de prueba (cuadre al c�ntimo)

**Checkpoint**: Arqueo consultable y cuadrado

---

## Phase 5: User Story 3 + US8 � Desglose de arqueo por denominaci�n / puesto (Priority: P2)

**Goal**: Desglose `Moneda01`�`Moneda20` por sesi�n y puesto; US8 = mismo desglose filtrado por puesto (sin men� Registradora)

**Independent Test**: Seleccionar sesi�n+puesto muestra denominaciones; cambiar puesto actualiza desglose; no hay ruta `/ventas/registradora`

### Implementation for User Story 3 / US8

- [X] T027 [US3] Extender `ArqueoService` (o m�todo dedicado) en `descartes-api/src/Services/Ventas/ArqueoService.php` para devolver vector denominaciones 1�20 por forma de pago / agregado
- [X] T028 [US3] Implementar `GET /api/ventas/arqueos/desglose` en routes/controller seg�n contrato OpenAPI
- [X] T029 [P] [US3] A�adir `obtenerArqueoDesglose` en `descartes-gestion/src/api/ventas.ts`
- [X] T030 [US3] Crear `descartes-gestion/src/views/ventas/ArqueoDesgloseView.vue` con filtros empresa/puesto/sesi�n (y opcional formaPago); UI clara si no hay denominaciones
- [X] T031 [US3] Ruta `/ventas/arqueo/desglose` en router; confirmar que **no** se a�ade submen� ni ruta �Registradora� (FR-020 / US8)
- [X] T032 [US8] Documentar en hint de UI de `ArqueoDesgloseView.vue` que el filtro de puesto equivale a la caja/registradora f�sica

**Checkpoint**: Desglose usable; caso Registradora cubierto por filtro puesto

---

## Phase 6: User Story 4 � Diario de anulaciones (Priority: P2)

**Goal**: Listado cronol�gico solo lectura de `LogAnulaciones` con filtros fecha/cajero/motivo

**Independent Test**: Para una fecha con anulaciones, se ve cajero, art�culo, motivo, puesto/sesi�n sin tocar la BD

### Implementation for User Story 4

- [X] T033 [US4] Implementar `descartes-api/src/Services/Ventas/AnulacionConsultaService.php` sobre `LogAnulaciones` (opcional join `Motivos` para etiqueta)
- [X] T034 [US4] Implementar `GET /api/ventas/anulaciones` (filtros fechaDesde/Hasta, cajero, motivo; paginaci�n) � **sin** POST/PUT
- [X] T035 [P] [US4] A�adir `listarAnulaciones` en `descartes-gestion/src/api/ventas.ts`
- [X] T036 [US4] Crear `descartes-gestion/src/views/ventas/AnulacionesView.vue` (rejilla cronol�gica + filtros; motivo vac�o ? �Sin motivo�)
- [X] T037 [US4] Ruta `/ventas/anulaciones` en router + nav

**Checkpoint**: Diario de anulaciones solo consulta (FR-021)

---

## Phase 7: User Story 6 � Liquidaci�n de vales (Priority: P2)

**Goal**: Emitir, listar pendientes y liquidar vales; bloquear caducados y ya liquidados

**Independent Test**: Emitir ? liquidar OK; segundo liquidar y vale caducado ? 409 + mensaje; SC-003

### Implementation for User Story 6

- [X] T038 [US6] Implementar `descartes-api/src/Services/Ventas/ValeService.php` (listar, emitir INSERT `Vales`, liquidar UPDATE con reglas caducidad/Liquidado)
- [X] T039 [US6] Endpoints `GET/POST /api/ventas/vales` y `POST /api/ventas/vales/{empresa}/{codigo}/liquidar` con permisos `ver`/`crear`/`editar` seg�n contrato
- [X] T040 [P] [US6] Cliente API vales en `descartes-gestion/src/api/ventas.ts` (`listarVales`, `emitirVale`, `liquidarVale`)
- [X] T041 [US6] Crear `descartes-gestion/src/views/ventas/ValesView.vue` (listado pendientes/todos, formulario emisi�n, acci�n liquidar con fecha + tipoLiquidacion)
- [X] T042 [US6] Bloqueo UX + mensajes claros para vale caducado o ya liquidado; ocultar/deshabilitar liquidar sin `ventas.editar`
- [X] T043 [US6] Ruta `/ventas/vales` en router + nav

**Checkpoint**: Ciclo de vida vales con bloqueos SC-003

---

## Phase 8: User Story 5 � Cobros y pagos (Priority: P3)

**Goal**: Listado de movimientos derivados de slots Fpago de ventas, clasificados por `FormasPago.CobroPago`

**Independent Test**: Filtros tipo cobro/pago, forma pago, fecha, puesto producen el subconjunto esperado

### Implementation for User Story 5

- [X] T044 [US5] Implementar `descartes-api/src/Services/Ventas/CobroPagoConsultaService.php` expandiendo `Fpago1/2` (+ slots adicionales si existen en cab) � `ImpFpago*` con join `FormasPago.CobroPago` (mapear C/P ? cobro/pago tras muestreo BD)
- [X] T045 [US5] Implementar `GET /api/ventas/cobros-pagos` en routes/controller (solo lectura)
- [X] T046 [P] [US5] A�adir `listarCobrosPagos` en `descartes-gestion/src/api/ventas.ts`
- [X] T047 [US5] Crear `descartes-gestion/src/views/ventas/CobrosPagosView.vue` con filtros tipo/formaPago/fecha/puesto
- [X] T048 [US5] Ruta `/ventas/cobros-pagos` en router + nav

**Checkpoint**: Cobros/pagos consultables sin tablas nuevas

---

## Phase 9: User Story 7 � Pedidos de clientes e impresi�n (Priority: P3)

**Goal**: Alta/consulta/seguimiento de `PedidosClientes` + acci�n marcar Impreso

**Independent Test**: Crear pedido con ?1 l�nea; marcar impreso con `editar`; usuario solo-ver no puede marcar ni crear

### Implementation for User Story 7

- [X] T049 [US7] Implementar `descartes-api/src/Services/Ventas/PedidoClienteService.php` (list/get, create cab+lin con ?1 l�nea, marcar `Impreso=1`)
- [X] T050 [US7] Endpoints `GET/POST /api/ventas/pedidos-clientes`, `GET .../{empresa}/{pedido}`, `POST .../impreso` con permisos `ver`/`crear`/`editar`
- [X] T051 [P] [US7] Cliente API pedidos en `descartes-gestion/src/api/ventas.ts`
- [X] T052 [US7] Crear `descartes-gestion/src/views/ventas/PedidosClientesView.vue` (listado + filtros estado/cliente/fecha)
- [X] T053 [US7] Crear formulario alta/detalle `descartes-gestion/src/views/ventas/PedidoClienteFormView.vue` (cabecera + l�neas; validar ?1 l�nea)
- [X] T054 [US7] Acci�n �Imprimir / Marcar impreso� (bot�n) que llama endpoint impreso; deshabilitada sin `ventas.editar`
- [X] T055 [US7] Rutas `/ventas/pedidos` y `/ventas/pedidos/:empresa/:pedido` en router + nav
- [X] T056 [US7] Mostrar enlace/informativo a venta asociada si `AlbaranesVentasCab.Pedido` existe (sin cobrar desde Gesti�n)

**Checkpoint**: Pedidos + Impreso; SC-005/SC-006

---

## Phase 10: Polish & Cross-Cutting Concerns

**Purpose**: Permisos, sync, validaci�n quickstart, limpieza

- [X] T057 [P] Revisar matriz permisos roles UI (`descartes-gestion/src/config/mantenimiento-nav-permisos.ts` u hom�logo) para que m�dulo `ventas` muestre acciones ver/crear/editar/eliminar coherentes con FR-016�019
- [X] T058 [P] Mensajes de error API en espa�ol (`message`) para 403/409 vales y validaciones pedidos en controllers/services Ventas
- [X] T059 Asegurar que ning�n endpoint de ventas permita UPDATE de importes de `AlbaranesVentasCab/Lin` (auditor�a r�pida de `Routes/ventas.php`)
- [X] T060 Ejecutar checklist de `descartes-specs/specs/002-ventas-gestion/quickstart.md` (smoke curl + UI) y anotar resultado
- [X] T061 [P] Sincronizar `descartes-api` hacia `C:\xampp\htdocs\descartes-api\` si el entorno de prueba usa XAMPP htdocs
- [X] T062 [P] Actualizar hint/empty states consistentes en todas las vistas `descartes-gestion/src/views/ventas/*.vue` (sin datos / sin permiso)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1 Setup** ? sin dependencias
- **Phase 2 Foundational** ? depende de Setup; **bloquea** todas las US
- **US1 (P1)** ? tras Foundational � **MVP**
- **US2 (P1)** ? tras Foundational (puede ir en paralelo a US1 si hay capacidad)
- **US3+US8 (P2)** ? ideal tras US2 (reutiliza ArqueoService)
- **US4 (P2)** ? tras Foundational (independiente de US1�3)
- **US6 (P2)** ? tras Foundational (independiente)
- **US5 (P3)** ? tras Foundational; mejor tras T005/T006 (flags formas-pago)
- **US7 (P3)** ? tras Foundational
- **Polish** ? tras US deseadas

### User Story Dependencies

| Story | Depende de | Independiente si |
|-------|------------|------------------|
| US1 Ventas | Foundational | S� |
| US2 Arqueo | Foundational + T005 flags | S� (con formas-pago OK) |
| US3/US8 Desglose | US2 service preferible | Puede compartir ArqueoService |
| US4 Anulaciones | Foundational | S� |
| US6 Vales | Foundational | S� |
| US5 Cobros | Foundational + T005 | S� |
| US7 Pedidos | Foundational | S� |

### Parallel Opportunities

```text
Tras Phase 2:
  Dev A: US1 (T013�T020)
  Dev B: US2?US3 (T021�T032)
  Dev C: US4 (T033�T037) || US6 (T038�T043)
Luego: US5 + US7 en paralelo
```

### Parallel Example: User Story 1

```bash
# Tras T013�T015 (API lista):
Task: "T016 [P] [US1] A�adir listarVentas/obtenerVenta en descartes-gestion/src/api/ventas.ts"
# Luego UI secuencial T017?T019
```

### Parallel Example: Foundational

```bash
Task: "T005 Extender entities.php formas-pago"
Task: "T006 [P] UI formas-pago cobroDeArqueo/cobroPago"
Task: "T011 [P] types/ventas.ts"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Completar Phase 1 + Phase 2  
2. Completar Phase 3 (US1)  
3. **STOP y validar** SC-001 (localizar venta del d�a en ?3 acciones)  
4. Demo / feedback

### Incremental Delivery

1. Setup + Foundational  
2. US1 Ventas ? MVP  
3. US2 Arqueo ? SC-002  
4. US3 Desglose (+ US8 filtro puesto)  
5. US4 Anulaciones ? SC-004  
6. US6 Vales ? SC-003  
7. US5 Cobros/Pagos  
8. US7 Pedidos ? SC-005/006  
9. Polish + quickstart

### Suggested MVP scope

**Solo US1** (consulta ventas) tras Foundational. Arqueo (US2) es el siguiente incremento P1 cr�tico para caja diaria.

---

## Notes

- Sin tareas de test automatizado (no pedidas en spec).  
- Sin migraciones SQL.  
- No implementar submen� �Desglose de arqueo Registradora�.  
- No implementar escritura en diario de anulaciones.  
- Paths absolutos de trabajo: `c:\descartes-2.0\descartes-api\�`, `c:\descartes-2.0\descartes-gestion\�`.  
- Total: **62 tareas** (T001�T062).
