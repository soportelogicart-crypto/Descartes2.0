# Feature Specification: Venta táctil (TPV)

**Feature Branch**: `006-tpv-venta-tactil`  
**Created**: 2026-08-31  
**Status**: Draft  
**Input**: User description: "Siguiente paso: hacer la venta táctil (TPV de caja en Descartes 2.0)."

## Contexto

En Descartes 1.0 el **TPV / venta táctil** es el programa de **caja**: pantalla a pantalla completa
con rejilla de botones configurables, escáner, cobro, ticket térmico e integración con sesión de
caja (`Sesiones`) y arqueo. Los datos persisten en las mismas tablas que consulta Gestión → Ventas.

| Concepto | Tablas / artefactos legacy |
|----------|----------------------------|
| Venta (ticket/albarán) | `AlbaranesVentasCab` + `AlbaranesVentasLin` |
| Sesión de caja | `Sesiones` |
| Teclado táctil | `Teclados` + `DefPlus` + `DefPlusC` |
| Clasificación artículos en rejilla | `Secciones`, `SubSecciones` (vía botones / artículos) |
| Configuración caja | `Puestos` (`Teclado`, impresora tickets, tienda, tarifa…) |
| Maestros | `Articulos`, `ArtPrecios`, `Clientes`, `FormasPago`, `Vendedores` |

En Descartes 2.0 hoy:

- El menú **TPV** en Gestión es un **placeholder**.
- **Nueva venta** (`/ventas/nuevo`) es flujo **administrativo** (teclado, campos, F4…) — no táctil.
- La **API de ventas** (`VentaEscrituraService`) ya crea cabecera, líneas, finaliza cobro, actualiza
  arqueo (`Add_Arq`) y sesión — **reutilizable** desde el TPV.
- **Arqueo operativo** (003) vive en Gestión → Ventas → Arqueo; el TPV **consume** la misma API de
  sesiones pero no sustituye la UI de arqueo en v1.
- **descartes-electron** carga la UI desde servidor y expone impresora ticket, config de puesto y
  stubs de cajón/báscula/visor.

Este spec define el **programa de venta táctil** según la constitución (Principio I: Gestión y Venta
son programas independientes vía API; Principio II: **offline-first**; Principio VIII: Electron +
periféricos).

**Mercado v1**: centros de **jardinería (Garden)** — retail en mostrador. Bares/pastelería (peso,
restaurante) quedan fuera o como fase posterior.

**Fuera de alcance v1**: mesas y cubiertos, producción de cocina, arqueo de camarero/chef, hotel/minibar,
venta por peso con báscula (estudio fase 2), diseñador visual de teclados (solo **consumir** `DefPlus`
existente), sustituir Gestión en consulta de ventas o en arqueo completo.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Abrir caja y pantalla TPV (Priority: P1)

Un cajero abre la aplicación TPV en **Electron** (pantalla completa en el puesto configurado).
El sistema identifica empresa/tienda y puesto (`equipo.json` + `Puestos`), comprueba permiso
`tpv`, asegura **sesión de caja abierta** (`Sesiones`) y muestra la pantalla de venta táctil
lista para cobrar.

**Why this priority**: Sin puesto, sesión y permisos no hay venta legal ni trazabilidad de caja.

**Independent Test**: Con puesto válido y usuario autorizado, al entrar en TPV se ve la rejilla
(o pantalla de espera clara) y la sesión activa del puesto; sin permiso o sin puesto configurado
el acceso se bloquea con mensaje explícito.

**Acceptance Scenarios**:

1. **Given** usuario con permiso `tpv.ver` (o acción acordada en plan) y puesto con tienda asignada,
   **When** abre TPV en Electron, **Then** se resuelve contexto de puesto/tienda y se muestra la UI táctil.
2. **Given** no hay sesión abierta para ese puesto, **When** entra en TPV, **Then** el sistema crea o
   reutiliza sesión según reglas legacy (`asegurarSesionPuesto`) e informa el nº de sesión.
3. **Given** usuario sin permiso TPV, **When** intenta acceder, **Then** el acceso se deniega.
4. **Given** puesto sin `Teclado` o teclado inexistente en BD, **When** entra en TPV, **Then** se
   informa el error y no se muestra una rejilla vacía sin explicación.

---

### User Story 2 - Vender con rejilla táctil legacy (Priority: P1)

El cajero vende tocando botones del teclado configurado (`Puestos.Teclado` → `DefPlus` / `DefPlusC`):
navega niveles (familias/subfamilias/platos según `H_NIVEL`, `H_NIVOP`, `H_NIVOB`), pulsa artículos
(`H_VALOR1` = código artículo) y ve el **ticket en curso** (líneas, cantidades, importe total).
Puede corregir cantidad o borrar línea antes de cobrar.

**Why this priority**: Es la experiencia distintiva de la venta táctil frente a la venta administrativa.

**Independent Test**: Con teclado legacy cargado en BD, pulsar un botón de artículo añade la línea
con precio/tarifa del puesto; el total se actualiza; borrar línea reduce el total.

**Acceptance Scenarios**:

1. **Given** teclado con botón de artículo válido, **When** el cajero lo pulsa, **Then** se añade
   línea con descripción, cantidad (por defecto 1 salvo regla de peso futura) e importe coherente
   con tarifa del puesto.
2. **Given** botón de **subnivel** (sin artículo), **When** se pulsa, **Then** la rejilla muestra
   el nivel hijo correspondiente (`H_NIVOP` / navegación acordada en plan).
3. **Given** botón **volver** / nivel padre, **When** se pulsa, **Then** regresa al nivel anterior.
4. **Given** artículo de baja o bloqueado, **When** se intenta vender, **Then** el sistema avisa y
   no añade la línea.
5. **Given** venta en curso, **When** el cajero modifica cantidad o elimina línea, **Then** el total
   se recalcula sin persistir documento cerrado.

---

### User Story 3 - Cobrar, finalizar e imprimir ticket (Priority: P1)

El cajero pulsa **Cobrar** (o equivalente táctil), elige **tipo de documento** (Ticket / Factura /
Albarán según reglas legacy y permisos), **forma(s) de pago** y confirma. El sistema **finaliza** la
venta en SQL Server, actualiza **stock** (`RebajeStock`), **arqueo de sesión** (`Add_Arq`) y
**imprime ticket** en la impresora del puesto vía Electron (`printTicket`).

**Why this priority**: Completar el ciclo de cobro es el objetivo del TPV; sin esto no hay negocio.

**Independent Test**: Venta de 2 líneas → cobro en efectivo como Ticket → documento cerrado en BD,
movimiento en arqueo de la sesión, ticket enviado a impresora (o preview en dev sin hardware).

**Acceptance Scenarios**:

1. **Given** venta con al menos una línea, **When** finaliza como **Ticket** con forma de pago de
   contado, **Then** el documento queda cerrado, `Sesion` asignada, stock rebajado y arqueo incrementado.
2. **Given** puesto con impresora de tickets configurada, **When** finaliza, **Then** se genera e
   imprime ticket (plantilla configurada en puesto / scope tickets).
3. **Given** cliente de contado sin nombre, **When** cobra sin buscar cliente, **Then** se permite
   venta rápida (`ZZZZZZZZZ` o regla legacy equivalente).
4. **Given** intento de finalizar sin líneas, **When** confirma cobro, **Then** el sistema rechaza.
5. **Given** forma de pago incompatible con tipo documento (p. ej. contado directo → albarán),
   **When** finaliza, **Then** se muestra error legacy coherente con `VentaEscrituraService`.

---

### User Story 4 - Venta offline-first (Priority: P1)

Con **sin conexión** a la API/SQL Server, el cajero puede **seguir vendiendo**: la venta se guarda
en **SQLite local**, se imprime ticket localmente y queda en **cola de sincronización**. Al
recuperar la red, la cola reintenta hasta persistir en SQL Server sin duplicar cobros (idempotencia
documentada en plan).

**Why this priority**: Principio II de la constitución — **NO NEGOCIABLE** para el programa Venta.

**Independent Test**: Desconectar red → venta completa + ticket → reconectar → venta aparece en
Gestión → Ventas con mismos importes y líneas.

**Acceptance Scenarios**:

1. **Given** TPV sin conectividad, **When** el cajero completa una venta, **Then** la operación
   termina en local (SQLite + ticket) en menos de 10 s percibidos.
2. **Given** ventas en cola local, **When** vuelve la conexión, **Then** se sincronizan en orden
   con reintentos y estado visible (pendiente / error / ok).
3. **Given** fallo de sync por conflicto de numeración, **When** reintenta, **Then** el sistema
   resuelve o marca error operativo sin perder el registro local.
4. **Given** modo offline prolongado, **When** el stock local no puede validarse contra servidor,
   **Then** la política (vender igual / avisar / bloquear) MUST documentarse en `research.md` y
   aplicarse de forma consistente.

---

### User Story 5 - Escáner de código de barras (Priority: P2)

El cajero escanea un EAN/código interno (lector HID como teclado) y el artículo se añade al ticket
en curso con la misma lógica de precios que un botón táctil.

**Why this priority**: Operativa habitual en garden center; independiente de la rejilla.

**Independent Test**: Escanear código de artículo existente añade línea; código inexistente muestra aviso.

**Acceptance Scenarios**:

1. **Given** venta abierta, **When** escanea código válido, **Then** añade línea (incrementa cantidad
   si ya existe el mismo artículo, según regla acordada en plan).
2. **Given** código desconocido, **When** escanea, **Then** aviso claro sin cerrar la venta.

---

### User Story 6 - Cliente y funciones de teclado frecuentes (Priority: P2)

Desde botones especiales del teclado (`Teclados.Tcl_*` mapeados en plan) o acciones fijas en UI, el
cajero puede: buscar **cliente**, aplicar **descuento** (si permiso), **consultar artículo**,
iniciar **abono** (si permiso) o **anular** línea/venta en curso según reglas legacy.

**Why this priority**: Paridad mínima con caja 1.0 sin implementar todo el teclado restaurante.

**Independent Test**: Asignar cliente desde buscador táctil; aplicar dto % en línea; consulta artículo
muestra stock/precio sin añadir.

**Acceptance Scenarios**:

1. **Given** botón cliente o acción «Cliente», **When** selecciona un cliente activo, **Then** la
   venta en curso usa ese cliente en cabecera.
2. **Given** permiso de descuento, **When** aplica descuento a línea, **Then** importes recalculados.
3. **Given** consulta artículo, **When** introduce código, **Then** muestra datos sin modificar ticket
   hasta confirmación explícita.

---

### User Story 7 - Integración con Gestión (Priority: P3)

Las ventas cerradas en TPV aparecen en **Gestión → Ventas** (listado/detalle existente) sin acción
adicional del cajero. El cierre de sesión y arqueo completo sigue en **Gestión → Arqueo** (003).

**Why this priority**: Cierra el circuito multi-programa; depende de US3–US4.

**Independent Test**: Tras vender en TPV, administración abre la venta del día en Gestión con mismos datos.

**Acceptance Scenarios**:

1. **Given** venta sincronizada desde TPV, **When** usuario de Gestión filtra por puesto y fecha,
   **Then** ve la venta y detalle coherentes.
2. **Given** sesión abierta en TPV, **When** responsable arquea en Gestión, **Then** totales cuadran
   con movimientos de ventas de esa sesión.

---

### Edge Cases

- Teclado legacy con botones vacíos o `H_VALOR1` inválido: ignorar o mostrar celda deshabilitada; no error fatal.
- Artículo **sin PVP** en la tarifa del puesto (p. ej. los «DIVERSOS» `1600` / `1621` de `larasa`, con
  `PrecioVen1 = 0`): el TPV MUST pedir el precio al cajero antes de crear la línea, y MUST permitir
  rectificarlo después sobre la línea seleccionada.
- Colores/tamaños de botones (`H_COLOR`, `H_ANCHO`, `H_ALTO`): renderizar de forma aproximada en v1;
  paridad pixel-perfect no es requisito.
- Multi-tienda: el puesto fija la tienda (`Empresa`); no permitir vender en tienda distinta sin permiso especial.
- Dos cajeros mismo puesto: política legacy (una sesión por puesto); no abrir dos sesiones simultáneas.
- Impresora ausente o error RAW: venta puede quedar cerrada en BD pero MUST avisar reimpresión desde Gestión.
- TicketBAI / Veri*Factu: al facturar desde TPV MUST respetar Principio V (delegar en servicios de
  facturación existentes; no romper trazabilidad).
- Actualización de UI en caliente: Electron recarga desde servidor; venta en curso MUST persistir
  localmente antes de recargar (draft en SQLite).
- Usuario cierra sesión Windows con venta abierta: recuperar borrador al reabrir si existe en local.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST exponer el **programa TPV** (venta táctil) accesible desde Electron,
  sustituyendo el placeholder `/tpv`, con UI optimizada para pantalla táctil (botones grandes,
  sin dependencia de atajos de teclado administrativos).
- **FR-002**: El TPV MUST resolver **puesto**, **tienda** y **teclado** desde configuración local +
  maestro `Puestos` (`Teclado`, impresora tickets, tarifa).
- **FR-003**: El TPV MUST cargar la definición del teclado desde tablas legacy **`DefPlus`** /
  **`DefPlusC`** filtradas por código de teclado del puesto, con navegación entre niveles.
- **FR-004**: El TPV MUST mantener un **ticket en curso** (cabecera + líneas) hasta cobrar o cancelar;
  MUST recalcular totales al cambiar líneas.
- **FR-005**: El TPV MUST crear y cerrar ventas usando las mismas tablas que legacy
  (`AlbaranesVentasCab` / `AlbaranesVentasLin`) vía API (`descartes-api`), reutilizando
  `VentaEscrituraService` donde sea posible.
- **FR-006**: Al finalizar cobro, el TPV MUST asignar **sesión** (`Sesiones`), actualizar **arqueo**
  (`Add_Arq`) y **rebajar stock** según reglas ya implementadas en API de ventas.
- **FR-007**: El TPV MUST imprimir **ticket** vía `descartes-electron` (`printTicket`) usando
  plantilla e impresora del puesto.
- **FR-008**: El TPV MUST cumplir **offline-first** (Principio II): venta completa sin red usando
  SQLite local + cola de sincronización; ningún paso crítico de cobro MUST depender de una llamada
  HTTP síncrona exitosa.
- **FR-009**: El TPV MUST integrar permisos configurables desde Roles; módulo lógico **`tpv`**
  (acciones mínimas: `ver`, `crear` venta, `editar` descuentos/anulaciones en curso — detalle en plan).
- **FR-010**: Gestión MUST NOT ser requisito para cobrar en TPV; comunicación **solo vía API**
  (Principio I).
- **FR-011**: El TPV MUST soportar **venta rápida sin cliente** (`ZZZZZZZZZ` o equivalente legacy).
- **FR-012**: El TPV MUST soportar finalización como **Ticket** en v1; **Factura** solo si la API
  actual lo permite sin regresión fiscal (TicketBAI/Veri*Factu).
- **FR-013**: El TPV MUST NOT implementar en v1: mesas, cubiertos, producción cocina, arqueo camarero,
  venta por peso con báscula, diseñador de teclados.
- **FR-014**: El TPV MUST NOT duplicar la UI de **arqueo completo** de Gestión; puede mostrar
  situación/resumen de sesión y enlace informativo a Gestión.
- **FR-015**: La API MUST exponer endpoints dedicados **`/api/tpv/...`** cuando el contrato difiera
  del CRUD administrativo de `/api/ventas` (p. ej. carga de teclado, borrador offline, sync);
  MUST usar sesión/autenticación y `PermissionMiddleware`.

### Key Entities

- **Teclado táctil (`Teclados`)**: definición lógica del teclado asignado al puesto.
- **Botón (`DefPlus`)**: celda con etiquetas, tamaño, color, valor (artículo o subnivel), navegación.
- **Cabecera teclado (`DefPlusC`)**: metadatos del layout por `H_GENERAL`.
- **Ticket en curso**: borrador de venta antes de finalizar (local offline + opcional espejo servidor).
- **Venta cerrada**: albarán/ticket en `AlbaranesVentasCab` con líneas y formas de pago.
- **Sesión de caja (`Sesiones`)**: periodo operativo del puesto; enlace de ventas y arqueo.
- **Cola de sincronización**: registros locales pendientes de persistir en SQL Server.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un cajero entrenado completa una venta de 3 artículos desde rejilla táctil + cobro +
  ticket en **≤ 60 segundos** en entorno de prueba con red.
- **SC-002**: Con red caída, el mismo flujo completa venta + ticket local en **≤ 10 segundos**
  percibidos sin error bloqueante.
- **SC-003**: Tras sincronizar, el 100 % de ventas offline de prueba aparecen en Gestión → Ventas
  con mismos totales (± 0,01 €).
- **SC-004**: Pulsar botón de artículo en teclado legacy cargado añade línea correcta en **≥ 95 %**
  de botones con `H_VALOR1` válido en BD de referencia.
- **SC-005**: Usuario sin permiso `tpv` no accede a rutas `/tpv*`.
- **SC-006**: Ticket impreso (o captura en dev) incluye líneas e importe total coherentes con BD.

---

## Assumptions

- Se reutilizan tablas legacy sin renombrar; extensiones (p. ej. tabla de cola sync) MUST justificarse
  en `data-model.md` del plan.
- El mapeo `Puestos.Teclado` → `DefPlus.H_GENERAL` se validará en BD real en `research.md`
  (formato exacto del código puede ser numérico de 3 dígitos).
- v1 prioriza **Garden/retail**; botones restaurante no mapeados se ignoran o quedan ocultos.
- Impresión ticket reutiliza plantillas del scope **tickets** ya existente en Configuración.
- Autenticación: sesión API igual que Gestión en v1 online; offline usa token/sesión cacheada con
  política de expiración documentada en plan.
- Numeración de documentos offline reserva rangos o reconcilia en sync (detalle en plan; MUST evitar
  duplicados).
- Arqueo detallado y cierre de sesión permanecen en Gestión (003) en v1.

---

## Open Questions

1. ¿`H_GENERAL` en `DefPlus` coincide exactamente con `Puestos.Teclado` (smallint) o hay padding/
   formato texto? → **Validar en `research.md` con SELECT en BD cliente.**
2. ¿Incrementar cantidad al repetir mismo artículo en ticket o crear línea nueva? → **Propuesta:**
   incrementar cantidad (legacy táctil habitual); confirmar en prueba 1.0.
3. ¿Factura desde TPV en v1 o solo Ticket (+ albarán si ya soportado)? → **Propuesta:** Ticket en MVP;
   Factura si API ya estable y fiscal OK.
4. ¿Política stock offline: vender sin validar stock remoto o bloquear? → **Propuesta:** vender con
   aviso suave; reconciliar en sync (documentar riesgo).
5. ¿Bundle UI TPV dentro de `descartes-gestion` (`/tpv`) o paquete Vue separado servido por misma URL?
   → **Propuesta:** ruta `/tpv` en gestión con layout fullscreen; Electron apunta a esa ruta.

*(Cerradas en [research.md](./research.md) R-001–R-009 y cierre de tabla.)*

---

## Fase posterior (no MVP): restaurante y peso

**Estado**: fuera de v1. Documentar en plan si se abre branch aparte.

- Mesas (`Tcl_Mesa`), cubiertos, producción, comandas.
- Venta por peso: báscula/visor vía `readScale` / `displayPrice` en Electron (Garden secundario;
  pastelería/bar).

**Entregable futuro**: `research-tpv-restaurante-peso.md` con go/no-go; sin tareas en MVP.
