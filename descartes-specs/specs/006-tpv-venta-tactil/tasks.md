---
description: "Task list for Venta táctil (TPV)"
---

# Tasks: Venta táctil (TPV)

**Input**: Design documents from `specs/006-tpv-venta-tactil/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md  
**Contract**: [contracts/tpv-api.openapi.yaml](./contracts/tpv-api.openapi.yaml)

**Tests**: No solicitados en spec — smoke manual al cerrar cada US (ver quickstart.md).

**Organization**: Por user story (US1–US7). Restaurante/peso **fuera** de este listado.

**Monorepo root**: `c:\descartes-2.0\`

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Paralelizable  
- **[USn]**: User story del spec.md

## Path Conventions

- **API**: `descartes-api/src/`
- **Gestión**: `descartes-gestion/src/`
- **Electron**: `descartes-electron/electron/`
- **BD**: `db/migrations/` (permisos); legacy en `db/script.sql`
- **Specs**: `descartes-specs/specs/006-tpv-venta-tactil/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Carpetas y cableado mínimo del módulo TPV

- [X] T001 Crear `descartes-api/src/Routes/tpv.php` (stub) y registrarlo en bootstrap/`public/index.php`
- [X] T002 [P] Crear `descartes-api/src/Controllers/TpvController.php` y carpeta `Services/Tpv/`
- [X] T003 [P] Crear `descartes-gestion/src/views/tpv/`, `api/tpv.ts`, `types/tpv.ts`, `stores/tpvVenta.ts`
- [X] T004 [P] Crear `descartes-gestion/src/layouts/TpvLayout.vue` (fullscreen, sin menú lateral)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Permisos, router, tipos — bloquea todas las US

**⚠️ CRITICAL**: Completar antes de US1+

- [X] T005 Ejecutar / verificar `db/migrations/007-permisos-tpv.sql` (rol ADMIN → módulo `tpv`)
- [X] T006 Verificar módulo `tpv` en `RolService::MODULOS` y matriz permisos: sección propia **TPV** en `RolPermisosMatrix.vue` vía `config/tpv-nav.ts` (antes solo en «Otros módulos»)
- [X] T007 Registrar rutas `/api/tpv/*` con `PermissionMiddleware` (`ver` GET; `crear` POST venta/sync; `editar` descuentos)
- [X] T008 Ruta `/tpv` en `router/index.ts` con guard `puede('tpv','ver')`. **Desvío del plan**: se integra dentro de `AppLayout` (menú lateral visible, `meta.contentFlush`) por petición de usuario; `TpvLayout.vue` queda disponible para modo caja dedicada en Electron
- [X] T009 [P] Tipos TS alineados a data-model (`TpvContexto`, `TpvNivel`, `TpvBoton`, borrador ticket)
- [X] T010 [P] Cliente API stub `pingTpv`, `obtenerContextoTpv` en `api/tpv.ts`
- [ ] T011 Documentar en plan/quickstart sync XAMPP tras cambios PHP

**Checkpoint**: `/tpv` accesible con `tpv.ver`; `GET /api/tpv/ping` responde

---

## Phase 3: User Story 1 — Abrir caja y pantalla TPV (Priority: P1) 🎯 MVP

**Goal**: Contexto puesto/tienda/sesión/teclado al entrar en TPV

**Independent Test**: Electron o browser `/tpv` muestra sesión activa o error claro; sin permiso → denegado

- [X] T012 [US1] `TpvContextoService.php` — resolver puesto (config equipo + `Puestos`), `H_GENERAL` padded, `ArqueoService::asegurarSesionPuesto`, tarifa, impresora
- [X] T013 [US1] `GET /api/tpv/contexto` en `TpvController`
- [X] T014 [P] [US1] Store `tpvVenta.ts`: cargar contexto al montar; estado sesión/tecladoGeneral
- [X] T015 [US1] `TpvVentaView.vue` — shell: cabecera (puesto, sesión, total 0); estados loading/error/sin teclado
- [X] T016 [US1] Integrar `usePuestoContextoStore` (`hydrateFromApi`) / equipo Electron con contexto API (empresa + puesto)
- [ ] T017 [US1] Smoke: usuario ADM entra en `/tpv` y ve nº sesión + teclado `001`

**Checkpoint**: Pantalla TPV operativa sin vender aún

---

## Phase 4: User Story 2 — Rejilla táctil + ticket en curso (Priority: P1)

**Goal**: Navegar `DefPlus` y mantener líneas en borrador

**Independent Test**: Pulsar artículo añade línea; total actualiza; borrar línea reduce total

- [X] T018 [US2] `TpvTecladoService.php` — `GET niveles/{nivel}`: query `DefPlus` + `DefPlusC`; tipo de botón por `H_M_C_MF` (`C` artículo / `F`,`M` familia) + `H_VALOR1` / `H_NIVOP`; posición desde `H_TECLA` (fila×20+columna) y color OLE → CSS. Ver R-002 ampliado
- [X] T019 [US2] `GET /api/tpv/teclados/{general}/niveles/{nivel}`
- [X] T020 [US2] `GET /api/tpv/articulos/{codigo}/precio` — `TpvArticuloService` con `PrecioVen{tarifa}` + `Impuestos.PjeIVA`
- [X] T021 [P] [US2] Componente `TpvTecladoGrid.vue` — filas legacy desde `H_TECLA`, anchos proporcionales a `H_ANCHO`, colores `H_COLOR`, stack de navegación con `H_NIVOP` / `H_NIVOB`
- [X] T022 [P] [US2] Componente `TpvTicketPanel.vue` — rejilla legacy (Cant./Descripción/Precio/Importe) con selección de línea y visor de total
- [X] T023 [US2] Store: añadir línea (incrementar cantidad si mismo artículo R-004); cantidad tecleada como multiplicador; recalcular total local
- [X] T024 [US2] Online: NUEVA VENTA solo **propone** número (`POST /api/ventas/reservar-albaran`, sin escritura; ver T044j: entrar en el TPV no reserva nada); la cabecera se crea con `POST /api/ventas` junto a la primera línea (cliente `ZZZZZZZZZ`, tienda, puesto) y las siguientes se persisten con `PUT`. Evita cabeceras vacías por cada entrada al TPV
- [X] T024b [US2] `TpvPrecioModal.vue` — artículos sin PVP en tarifa (`PrecioVen{n} = 0`): pedir precio antes de crear la línea; tecla `PRECIO` para rectificar la línea seleccionada
- [X] T024c [US2] Entrada por código: campo CODIGO/EAN siempre enfocado, resuelto con `GET /api/tpv/articulos/resolver` (código, Alternativo o EAN + precio de tarifa en una sola petición) — Intro para tecleo manual y `createBarcodeScanWatcher` para pistola; el EAN de paquete multiplica unidades.
      Endpoint propio del TPV con permiso `tpv.ver`: el de mantenimiento exige `articulos.ver`, que un rol de caja no tiene (era la causa de que "AÑADIR" no hiciera nada).
      Verificado con `php scripts/smoke-tpv-articulo.php <codigo|ean> [tarifa]`
- [X] T024d [US2] Distribución según croquis del usuario: ticket (izquierda) · CODIGO/EAN + botones artículos + ATRAS (centro) · teclado numérico + NUEVA VENTA + CANT ± + BORRAR LINEA + PRECIO + CLIENTE + DTO. + COBRAR + SALIR (derecha).
      `CANT −` ya no borra la línea al llegar a 0: mínimo 1 y aviso remitiendo a BORRAR LINEA. `NUEVA VENTA` abandona el ticket en curso (confirmación si tiene líneas) y propone número nuevo.
      Foco permanente en CODIGO/EAN: `mousedown` del cuerpo hace `preventDefault` salvo sobre inputs, así ningún botón roba el foco y la pistola siempre escribe donde debe.
      Anchos: ticket 46%, centro y acciones iguales (`1fr 1fr`); ambas columnas llegan al pie (filas del teclado reparten el alto según `H_ALTO`, teclado numérico y funciones en `flex`). ATRAS pasa a la barra de título (sin él, un subnivel sin tecla de retorno atraparía al cajero)
- [ ] T025 [US2] Smoke SC-004 parcial: botones con `H_VALOR1` en teclado `001` añaden línea correcta
      (`php scripts/smoke-tpv-teclado.php 001 001` lista tipo, destino y precio de cada botón)

**Checkpoint**: Táctil + ticket borrador sin cobro

---

## Phase 5: User Story 3 — Cobrar, finalizar e imprimir ticket (Priority: P1)

**Goal**: Cierre Ticket + arqueo + impresión

**Independent Test**: 2 líneas → Cobrar → Ticket efectivo → venta cerrada en BD + movimiento arqueo

- [X] T026 [US3] `TpvCobroModal.vue` — total y tipo de documento como en Gestión (Ticket, Albarán, Presupuesto o Factura); Factura solo con cliente real, NIF y razón social. Ticket/Factura obligan a seleccionar forma de pago (sin valor predeterminado), entregado con pad, cambio y tecla EXACTO.
      Las formas (`CobroDeArqueo = 1`, `Baja = 0`) llegan en `GET /api/tpv/contexto` para no exigir el permiso `formas-pago.ver` a un rol de caja.
      Verificado con `php scripts/smoke-tpv-contexto.php <empresa> <puesto>`
- [X] T027 [US3] Finalizar vía `finalizarVenta(..., tipo, fpago)` — tipos T/A/P/F y validaciones del mismo backend que Gestión; para Ticket/Factura se graba antes `Fpago1`/`ImpFpago1`
- [X] T028 [US3] Tras cobrar: aviso con nº de ticket y cambio, y `iniciar()` de nuevo (nuevo número propuesto, líneas a cero)
- [X] T029 [US3] Impresión: reuse `prepararOImprimirVenta`; Ticket va a la térmica y Albarán/Presupuesto/Factura abren la misma previsualización A4 que Gestión. Ticket automático salvo que el puesto tenga `ticketAutomatico` desactivado.
      Un fallo de impresora no se confunde con un fallo de cobro: el aviso verde conserva el nº de ticket y ofrece REIMPRIMIR / REINTENTAR. Requiere el agente Electron (sin él, avisa de que hay que abrir Descartes Electron)
- [X] T030 [US3] Botón **COBRAR** en la columna de acciones; exige ≥1 línea y cabecera ya grabada
- [ ] T031 [US3] Smoke SC-001 + SC-006: venta 3 artículos ≤60s; ticket coherente

**Checkpoint**: Demo online completa (plan MVP)

---

## Phase 6: User Story 7 — Integración Gestión (Priority: P3)

**Goal**: Ventas TPV visibles en Gestión → Ventas; arqueo cuadra

**Independent Test**: Tras T031, listado Ventas del día muestra la venta del puesto

- [ ] T032 [US7] Verificar campos listado ventas (`Puesto`, `Sesion`, importes) — ajuste mínimo si falta filtro por puesto en sesión abierta
- [ ] T033 [US7] Documentar en quickstart flujo verificación Gestión post-venta TPV
- [ ] T034 [US7] Smoke: venta TPV → Gestión → Ventas → detalle coincide

**Checkpoint**: Circuito multi-programa cerrado (online)

---

## Phase 7: User Story 4 — Offline-first (Priority: P1 — producción)

**Goal**: Vender sin red; sync idempotente al volver conexión

**Independent Test**: SC-002 + SC-003 (quickstart offline)

- [ ] T035 [US4] `descartes-electron/electron/tpv-db.js` — SQLite schema `tpv_draft`, `tpv_sync_queue`, `tpv_offline_meta`
- [ ] T036 [US4] Exponer en preload: `tpvSaveDraft`, `tpvLoadDraft`, `tpvEnqueueSync`, `tpvGetSyncStatus`, `tpvRunSync`
- [ ] T037 [US4] `POST /api/tpv/reservar-numeros` — bloque tickets para uso offline
- [ ] T038 [US4] `TpvSyncService.php` + `POST /api/tpv/sync/ventas` — idempotencyKey; opcional tabla `TpvSyncLog` + migración
- [ ] T039 [US4] `tpv-sync.js` — worker reintentos; pausa si 401/403
- [ ] T040 [US4] UI: indicador red (online/offline) + cola pendiente; cobro offline cierra local + ticket + encola
- [ ] T041 [US4] Smoke SC-002/SC-003: venta offline → sync → Gestión

**Checkpoint**: Constitution II cumplida para producción

---

## Phase 8: User Story 5 — Escáner (Priority: P2)

**Goal**: EAN/código interno añade línea

**Independent Test**: Escaneo artículo válido → línea; inválido → aviso

- [X] T042 [US5] Integrar `createBarcodeScanWatcher` en `TpvVentaView` → `resolverArticulo` → misma ruta que botón táctil (adelantado a US2, ver T024c)
- [ ] T043 [US5] Smoke: escáner HID añade línea en ticket abierto

---

## Phase 9: User Story 6 — Cliente y funciones teclado (Priority: P2)

**Goal**: Cliente, descuento, consulta artículo, cancelar ticket

**Independent Test**: Asignar cliente; dto en línea; consulta sin añadir

- [X] T044a [US6] Cliente: `GET /api/tpv/clientes?q=` (`TpvClienteService`, permiso `tpv.ver`) + `TpvClienteModal.vue`.
      Busca por código exacto, NIF, razón social o teléfono; asigna cliente/razón social/NIF/teléfono a la cabecera (o queda pendiente si aún no está grabada) y permite volver a venta rápida (`ZZZZZZZZZ`).
      Si la tarifa del cliente no es la de la caja se avisa, pero no se recalculan precios ya metidos.
      Verificado con `php scripts/smoke-tpv-cliente.php <texto>`
- [ ] T044b [US6] Acciones fijas UI restantes: Consulta artículo.
      **Cancelar ticket completado**: botón ANULAR COMPRA con confirmación; si la cabecera ya existe elimina el documento completo mediante `DELETE /api/tpv/ventas/{empresa}/{tipo}/{albaran}` (permiso `tpv.eliminar`) y deja la caja sin ticket. NUEVA VENTA, SALIR con venta pendiente y BORRAR la última línea usan la misma anulación para no dejar cabeceras huérfanas.
- [X] T044c [US6] Abono desde TPV: botón ABONO (solo con la pantalla de venta vacía) y `TpvAbonoModal.vue`. Busca por nº visible de ticket/factura o nº interno de albarán, carga el documento, aplica las mismas reglas de abonabilidad y líneas ya abonadas que Ventas → Ventas, permite abono parcial y observación, y crea mediante el mismo endpoint `POST .../abono`. Tras crear abre la ficha generada en Ventas, igual que el flujo de Gestión.
      Búsqueda acotada al documento: filtro `documento` en `VentaConsultaService::listar` (`Albaran` o `Factura`) más comprobación exacta en cliente por si la API no está sincronizada. El nº impreso (factura) tiene prioridad sobre el nº interno de albarán, de modo que un mismo número no devuelve dos documentos, y los abonos quedan excluidos como origen.
- [X] T044d [US6] Entrada táctil sin teclado físico: componente compartido `TpvTecladoNumerico.vue`; el modal de abono abre con el foco en el nº de documento y su propio pad, y la barra CODIGO/EAN incorpora la tecla TECLADO que abre `TpvCodigoModal.vue` para marcar un artículo a mano.
- [X] T044i [US6] **Fix**: BORRAR LINEA sobre la última línea dejaba la pantalla en blanco. `quitarLinea` llamaba a `anularVentaActual()`, que pone `reserva` a nulo; como `ticketListo` depende de ella, se desmontaba todo el cuerpo (ticket, teclado, funciones) y la caja quedaba inservible. Ahora usa `vaciarTicket()`: borra la cabecera para no dejar el documento huérfano y **recupera la misma reserva**, en vez de pedir otra, porque `reservarAlbaran` consume `Empresas.UltAlbaranVen` y el número acaba de quedar libre (no se abren huecos en la numeración ni cambia el ticket a la vista). Conserva también el cliente asignado. Además `TpvVentaView` gana un estado de respaldo (REINTENTAR / SALIR) para que un fallo al abrir la caja no deje una pantalla muerta
- [X] T044j [US6] **Ticket bajo demanda**: entrar en el TPV ya no propone número. `iniciar` se parte en `abrirCaja` (contexto de puesto/sesión/tarifa, sin escritura ni contador) y `abrirTicket` (reserva de número), que solo se llama desde NUEVA VENTA; cobrar tampoco reserva el siguiente (`cerrarTicket`), y ANULAR COMPRA deja la caja libre. Motivo: `reservarAlbaran` consume `Empresas.UltAlbaranVen`, así que abrir la pantalla o mirar precios abría huecos en la numeración. La pantalla completa se muestra con la caja abierta (`cajaAbierta`) en lugar de exigir reserva (`ticketListo`); sin ticket, NUEVA VENTA y ABONO quedan operativos y se deshabilitan ANULAR COMPRA, CLIENTE, DTO., la barra CODIGO/EAN (placeholder «Pulse NUEVA VENTA») y su tecla TECLADO. Las teclas de artículo avisan en lugar de vender y sí permiten navegar por los grupos; la cabecera muestra «Sin ticket» atenuado
- [X] T044h [US6] Teclado alfanumérico en pantalla: nuevo `TpvTecladoAlfanumerico.vue` (dígitos + QWERTY con Ñ, punto, guion y arroba, ESPACIO, ← y BORRAR TODO; emite en mayúsculas y no roba el foco con `mousedown.prevent`). `TpvClienteModal` incorpora la tecla TECLADO que lo muestra u oculta —la elección se mantiene mientras el TPV siga abierto, para cajas sin teclado físico— y la ventana se ensancha de 34 a 46 rem al mostrarlo para que quepan 10 teclas legibles por fila. Complementa a `TpvTecladoNumerico`, que solo da dígitos
- [X] T044g [US6] Barra CODIGO / EAN simplificada: se retira la tecla AÑADIR (redundante con Intro y con el AÑADIR del propio `TpvCodigoModal`, y deshabilitada mientras el input está vacío, que es su estado normal al trabajar con pistola). La barra pasa a dos filas —etiqueta arriba, input + TECLADO abajo— porque en una sola fila la columna central (~289 px a 1024x768) dejaba al input poco más de 110 px; así el input gana ancho y sube a 1,45 rem con alto mínimo de 2,9 rem
- [X] T044e [US6] Configuración táctil del teclado de venta rápida: `TpvTecladoGrid` usa una rejilla uniforme de 4 teclas por fila (todas del mismo tamaño) y completa como mínimo 8 filas de huecos para ocupar toda la columna; cada hueco toma la primera tecla legacy libre (`fila * 20 + columna * 5`) y se guarda con ancho 5. Modo CONFIGURAR BOTONES permite tocar un hueco o una tecla existente y asignar/cambiar un artículo, CREAR GRUPO NUEVO (el servidor reserva el primer nivel libre desde 900 y el TPV entra en el grupo vacío para rellenarlo) o enlazar un grupo ya existente; DEJAR VACÍO elimina la asignación. El artículo se puede teclear con el teclado numérico o buscar por código/descripción de artículo, macrofamilia, familia, subfamilia o agrupación (`GET /api/tpv/articulos?ambito=...`); los resultados muestran su clasificación. El texto del botón es editable (12 caracteres, límite de `DefPlus.H_ETIQUET1`). Persistencia directa en `DefPlus` mediante endpoints TPV con permiso `tpv.editar`. ATRÁS pasa de la cabecera al pie de la columna central y permanece visible (deshabilitado en raíz).
- [X] T044f [US6] Acciones posteriores al cierre compartidas entre TPV y Ventas → Ventas: preguntar siempre si se quiere IMPRIMIR, ENVIAR POR EMAIL o NO IMPRIMIR. Email editable con valor inicial de la cabecera; en TPV se copia `Clientes.EmailFacturacion` (fallback `Email`) al asignar cliente. `POST .../email` envía HTML y PDF adjunto mediante SMTP (PHPMailer), marca `Facturas.EnviadaPorEmail` cuando corresponde y no revierte la venta si impresión o correo fallan.
- [X] T045 [US6] Descuento de línea (permiso `tpv.editar`) — columna Dto. en `TpvTicketPanel`, tecla DTO. y `TpvDescuentoModal.vue` (0–100%, dos decimales). Recalcula importe/total local y persiste `pjeDto` mediante `PUT` de la venta
- [ ] T046 [US6] Mapear botones `Teclados.Tcl_Cliente`, `Tcl_Borra`, `Tcl_Descuento`, `Tcl_ConsArticulo`, `Tcl_NoVenta` si existen en teclado activo (research R-013)
- [ ] T047 [US6] Smoke: cliente asignado persiste en cabecera al cobrar

---

## Phase 10: Electron caja + polish

**Purpose**: Experiencia caja dedicada

- [ ] T048 [P] `descartes-electron/config.json` ejemplo `appUrl` → `http://localhost:5173/tpv`; fullscreen default
- [X] T049c [P] Pestañas seguras en táctil (`AppTabs`): ancho mínimo de 8 rem (7 en modo compacto) para que los nombres cortos no dejen la aspa pegada al texto, aspa de 1,35 rem alineada al borde derecho y **visible solo en la pestaña activa** (en las inactivas se reserva el hueco con `visibility: hidden`), de modo que cambiar de pestaña con el dedo no pueda cerrarla: hace falta un segundo toque deliberado. El atajo de doble clic que cierra las demás pestañas queda restringido a `(pointer: fine)`, porque un doble toque involuntario cerraba todo lo demás
- [X] T049a [P] Menú lateral compacto en `/tpv`: la ruta declara `meta.sidebarCompacta` y `AppLayout` reduce la barra a una franja de 3,25 rem con solo iconos (`ToolIcon`: mantenimiento, compras, etiquetas, ventas, facturación, inventario, listados, TPV) y `title` con el nombre. Al tocar una sección el menú se despliega a 240 px **flotando** sobre el contenido (posición absoluta + fondo oscuro que lo cierra), de modo que el TPV nunca pierde ancho. En esta ruta la barra de usuario se compacta y las pestañas se mantienen visibles en versión compacta (`AppTabs :compacta`): son necesarias para saltar a consulta de artículos y volver al TPV sin perder la venta en curso (`KeepAlive`)
- [X] T049b [P] Encaje en 1024x768 por **escalado proporcional de toda la aplicación**: `style.css` define en `:root` `--escala-ui: min(1.72vh, 1.35vw)` y `font-size: clamp(12px, var(--escala-ui), 16px)`; al estar todo medido en `rem`, tipografías, botones, separaciones y rejillas escalan a la vez conservando las proporciones (1024x768 → ~13,2 px, 83%; 1920x1080 → 16 px, sin cambios). `AppLayout` pasa el menú de 240 px a 15 rem para acompañar la escala. El TPV solo afina el factor (`html:has(.tpv) { --escala-ui: min(1.65vh, 1.35vw) }`) por ser la pantalla más densa, y la columna de acciones (pad + 10 funciones + COBRAR) entra sin recortes ni scroll. Se descartan los recortes de altura/tipografía uno a uno (`@media max-height`) por desequilibrar el conjunto —botones grandes con texto pequeño—; solo queda una media query de proporción: el ticket pasa de 46% a 40% por debajo de 1200 px
- [ ] T049 [P] Estilos TPV: touch targets ≥44px; ocultar scrollbars; tema alto contraste
- [ ] T050 Validar quickstart.md end-to-end (permisos, teclado BD, demo MVP)
- [ ] T051 Actualizar `contracts/tpv-api.openapi.yaml` si diverge de implementación real

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1–2**: Bloquean todo
- **US1 (3)**: Bloquea US2–US3
- **US2 (4)**: Bloquea US3
- **US3 (5)**: Primer demo online; US7 (6) verifica integración
- **US4 (7)**: Puede desarrollarse en paralelo tras US3, pero **obligatorio antes producción**
- **US5–US6 (8–9)**: Tras US3
- **Phase 10**: Final

### MVP recomendado (parar y validar)

Completar **T001–T031** + **T048** → demo: táctil + cobro + ticket + Gestión.

Offline (**T035–T041**) antes de desplegar caja real.

### Parallel Opportunities

- T002–T004 en paralelo (Setup)
- T009–T010 en paralelo (Foundational)
- T021–T022 en paralelo (US2 UI)
- T048–T049 en paralelo (polish)

---

## Implementation Strategy

1. **Sprint 1**: Phase 1–2 + US1 → pantalla TPV con sesión  
2. **Sprint 2**: US2 → rejilla + ticket  
3. **Sprint 3**: US3 + US7 + Electron config → **demo MVP**  
4. **Sprint 4**: US4 offline  
5. **Sprint 5**: US5 escáner + US6 extras  

---

## Notes

- Reutilizar al máximo `/api/ventas`; `/api/tpv` solo para contexto, teclado, sync y reserva números.
- Factura desde TPV: **no** en este listado (fase 2 spec).
- Si `DefPlus` vacío en BD cliente, usar script de datos prueba o teclado `001` de dev.
