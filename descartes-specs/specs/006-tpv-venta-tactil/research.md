# Research: 006-tpv-venta-tactil

**Feature**: Venta táctil (TPV)  
**Date**: 2026-08-31  
**Spec**: [spec.md](./spec.md)  
**Sources**: `db/script.sql`, BD `larasa` (dev), `VentaEscrituraService`, `ArqueoService`, `descartes-electron`, `003-arqueo-operativo/design.md`

## R-001 — Mapeo `Puestos.Teclado` → `DefPlus.H_GENERAL`

- **Decision**: `H_GENERAL = RIGHT('000' + CAST(Puestos.Teclado AS varchar(3)), 3)` (código a 3 dígitos con ceros a la izquierda).
- **Evidencia BD** (`larasa`): `Puestos.Teclado = 1` → filas en `DefPlus` con `H_GENERAL = '001'`; `DefPlusC.H_NOMBRE = 'Teclado 001'`; `Teclados.Codigo = 1`.
- **Rationale**: PK de `DefPlus` es `(H_GENERAL, H_NIVEL, H_TECLA)`; el puesto referencia `Teclados.Codigo` (smallint).
- **Alternatives considered**: Usar `Teclado` sin padding (falla join); tratar `H_GENERAL` como entero (tipo SQL es nvarchar(3)).

## R-002 — Estructura de botones (`DefPlus`)

| Campo | Uso en TPV v1 |
|-------|----------------|
| `H_NIVEL` | Nivel jerárquico (`'000'` raíz, `'001'` hijo, …). Stack de navegación en UI. |
| `H_TECLA` | Identificador de celda dentro del nivel (legacy: codificación de rejilla; **no** es fila×col simple). |
| `H_ANCHO` / `H_ALTO` | Unidades relativas de tamaño (span en rejilla CSS). |
| `H_ETIQUET1..3` | Texto visible del botón. |
| `H_VALOR1` | Código artículo (varchar 18) si el botón vende producto. |
| `H_NIVOP` | Nivel destino al pulsar (submenú). |
| `H_NIVOB` … `H_NIVOB4` | Niveles «volver» / alternativos (usar `H_NIVOB` primero en v1). |
| `H_COLOR`, `H_ICON` | Estilo; aproximación visual en v1. |
| `H_PRECIO` / `H_PRECIO2` | Flags legacy de mostrar precio en botón (opcional UI). |
| `H_TARIFA` | Tarifa override en botón; si null → tarifa del puesto. |

| `H_M_C_MF` | **Clase de botón**: `C` = código de artículo, `F` = familia, `M` = macrofamilia. |
| `H_COLLIT` | Color del literal (texto). |

- **Decision (tipo de botón)**:
  1. Si `RTRIM(H_VALOR1) <> ''` → **artículo** (resolver precio vía API artículos + tarifa puesto).
  2. Si `RTRIM(H_NIVOP) <> ''` → **ir a nivel** `H_NIVOP`.
  3. Si `H_M_C_MF` ∈ {`F`,`M`}, hay etiqueta y `H_NIVOP` está vacío → **nivel destino por ordinal**: la
     posición del botón dentro del nivel (orden `H_TECLA`, 1-based, zero-pad 3) y **solo** si ese nivel
     existe en `DefPlus`. Validado en `larasa`: nivel `000` botón 1 «DIVERSOS» → nivel `001`.
  4. Botones vacíos → celda deshabilitada.
- **Rationale**: Código VB 1.0 no está en monorepo; reglas 1–2 cubren la mayoría; 3 desbloquea el
  teclado real de dev, donde los 8 botones de `000` son `H_M_C_MF = 'F'` sin `H_NIVOP`.
- **Riesgo**: Teclados complejos restaurante pueden requerir mapeo `Teclados.Tcl_*`; fuera de v1 Garden.
- **Smoke**: `php scripts/smoke-tpv-teclado.php 001 000`.

### Colores legacy (`H_COLOR`, `H_COLLIT`)

- Enteros OLE de VB (`&H00BBGGRR`): `r = v & 0xFF`, `g = (v >> 8) & 0xFF`, `b = (v >> 16) & 0xFF`.
- Valores **negativos** son colores de sistema (bit alto activo, p. ej. `-2147483633` = `COLOR_BTNFACE`)
  → sin equivalente directo; se devuelve `null` y la UI aplica el gris de botón por defecto.
- Evidencia: `8421631` → `#ff8080`; `8454143` → `#ffff80`.

### Ajuste de escala de la rejilla (dic. ajuste UI)

Las teclas se empaquetan por la izquierda dentro de su fila (`grid-auto-flow: dense` con
`span H_ANCHO`), no por la columna absoluta de `H_TECLA`. Repartir el ancho entre las 20
columnas legacy dejaba botones de ~2 unidades convertidos en tiras verticales muy estrechas,
porque la columna del teclado ocupa un tercio de la pantalla y no los 20/20 del TPV original.

Decisión final: rejilla uniforme de **4 teclas por fila**, ignorando `H_ANCHO`/`H_ALTO` al pintar.
Todas las teclas miden lo mismo y las filas reparten el alto disponible (`grid-auto-rows:
minmax(3.4rem, 1fr)`), que es lo que se espera de una pantalla táctil: destino grande y previsible
en la misma posición. Las teclas configuradas se empaquetan por orden de `H_TECLA` y el resto de
celdas quedan como huecos asignables.

Para no romper el TPV legacy, cada hueco reserva la primera tecla libre de la forma
`fila × 20 + columna × 5` y se guarda con `H_ANCHO = 5`, de modo que la aplicación antigua también
dibuja cuatro botones por fila.

Los grupos creados desde el TPV se numeran desde `900`. La razón es la heurística de R-002 regla 3:
un botón de familia sin `H_NIVOP` deduce su nivel destino del ordinal dentro del nivel, así que
reutilizar códigos bajos (`002`, `003`…) haría que un botón legacy sin `H_NIVOP` empezara a abrir
un grupo nuevo por accidente. El rango 900–999 queda fuera del alcance de esos ordinales.

## R-003 — Rejilla visual (`H_TECLA`)

- **Decision**: `H_TECLA` codifica la posición en una rejilla de **20 columnas**: `fila = H_TECLA / 20`,
  `columna = H_TECLA % 20`. La UI agrupa los botones por `fila` (orden ascendente) y dentro de cada fila
  los ordena por `columna`, repartiendo el ancho proporcionalmente a `H_ANCHO` y la altura a `H_ALTO`.
- **Evidencia** (`larasa`, teclado `001` nivel `000`): teclas `275, 278, 315, 318, 355, 358, 395, 398`
  → filas `13, 15, 17, 19` (paso 2 = `H_ALTO`) y columnas `15, 18` (paso 3 = `H_ANCHO`). Coherente.
- **Rationale**: Colocar por líneas de rejilla absolutas provoca solapes cuando el diseñador legacy dejó
  botones a menos de `H_ANCHO` de distancia (ocurre en el nivel `001` de dev: columnas 1 y 3 con ancho 3).
  Agrupar por fila conserva la estructura legacy sin solapamientos. Paridad pixel-perfect **no** requerida.
- **Alternatives considered**: Canvas con coordenadas absolutas legacy (sobreingeniería v1); lista plana
  (mala UX táctil); CSS Grid con líneas explícitas (solapes con datos reales).

## R-004 — Ticket en curso y líneas duplicadas

- **Decision**: Al pulsar el **mismo artículo** en el ticket abierto → **incrementar cantidad** de la línea existente (no duplicar `NroLin`).
- **Rationale**: Comportamiento habitual TPV retail; reduce líneas en ticket.
- **Alternatives considered**: Siempre nueva línea (legacy administrativo); descartado para táctil.

## R-005 — Documento y cobro v1

- **Decision**: TPV v1 finaliza como **Ticket** (`tipoFinal = 'T'`) con forma de pago **contado** (`CobroDeArqueo` / agrupación arqueo). **Factura** desde TPV → **fase 2** salvo que prueba fiscal confirme paridad con `VentaEscrituraService` + Veri*Factu/TicketBAI sin cambios.
- **Rationale**: Spec FR-012; reduce riesgo fiscal en primer despliegue.
- **Reutilización**: `POST /api/ventas` (cabecera) + líneas + `POST .../finalizar` vía servicios existentes; TPV puede llamarlos directamente en modo online.

## R-006 — Sesión de caja

- **Decision**: Reutilizar `ArqueoService::asegurarSesionPuesto($puesto, $empresa)` (ya usado en `finalizar`).
- **Endpoint nuevo**: `GET /api/tpv/contexto` devuelve `{ empresa, puesto, sesion, tecladoCodigo, tecladoGeneral, tarifa, impresoraTickets, ... }`.
- **UI arqueo**: permanece en Gestión (003); TPV muestra nº sesión y totales acumulados opcionales (solo lectura).

## R-007 — Permisos (`tpv`)

- **Decision**:

| Acción | Capacidad TPV |
|--------|----------------|
| `ver` | Entrar en `/tpv`, ver ticket, consulta artículo |
| `crear` | Añadir líneas, cobrar/finalizar, escáner |
| `editar` | Descuento en línea, borrar línea, cambiar cliente en curso |

- **Migración**: `db/migrations/007-permisos-tpv.sql` — rol `ADMIN` con las cuatro acciones (patrón 006-compras).
- **Alternatives considered**: Submódulos `tpv-venta`, `tpv-cobro` (postergar).

## R-008 — UI y Electron

- **Decision**: Ruta **`/tpv`** en `descartes-gestion` dentro de `AppLayout` (`meta.contentFlush`), para que la caja siga siendo accesible desde el menú principal. Electron `config.json` puede usar `appUrl: http://localhost:5173/tpv` en caja.
- **Rationale**: Un despliegue Vue; Principio VIII (Electron fino).
- **Alternatives considered**: Paquete Vue separado (duplica build); app nativa solo Electron (duplica lógica). `TpvLayout.vue` (fullscreen sin menú) no se usa en esta ruta porque dejaba la caja sin navegación, pero queda disponible para el modo caja dedicada en Electron.

### Resolución de referencia: 1024x768

Muchas cajas usan pantallas de 1024x768, así que ese es el tamaño que debe caber sin recortes ni scroll:

- **Ancho**: el menú lateral se reduce en `/tpv` a una franja de iconos de 3,25 rem (`meta.sidebarCompacta`), dejando ~972 px de cuerpo. Al tocar una sección el menú se despliega a 240 px flotando en posición absoluta sobre el contenido, por lo que el TPV no se re-maqueta ni pierde ancho. El ticket baja del 46% al 40% por debajo de 1200 px: ~389 px de ticket y ~283 px para cada una de las otras dos columnas (unos 68 px por tecla de artículo y ~91 px por dígito del pad).
- **Alto**: en `/tpv` se compactan la barra de usuario y las pestañas (~50 px recuperados). Las pestañas **no** se ocultan: son la vía para consultar artículos y volver al TPV con la venta intacta (`KeepAlive`).
- **Tamaño**: **escalado proporcional** mediante la raíz de tipografía, no recortes medida a medida. Es una decisión **de toda la aplicación**, no solo del TPV: `style.css` declara `--escala-ui: min(1.72vh, 1.35vw)` y `font-size: clamp(12px, var(--escala-ui), 16px)` en `:root`. Como la aplicación está medida en `rem`, todo (tipografías, botones, separaciones, rejillas) escala manteniendo las proporciones del diseño. El tope de 16 px deja los monitores normales igual que antes y el suelo de 12 px evita que una ventana muy pequeña vuelva ilegible el texto. Referencias: 1024x768 → ~13,2 px (83%); 1366x768 → ~13,2 px; 1920x1080 → 16 px.
- **Ajuste del TPV**: `TpvVentaView` solo redefine el factor en un bloque global, `html:has(.tpv) { --escala-ui: min(1.65vh, 1.35vw) }`, porque es la pantalla más densa (ticket + teclado + pad + 10 funciones) y necesita algo más de margen vertical: a 768 px la raíz queda en ~12,7 px y la columna de acciones pasa de ~750 px a ~650 px sobre los ~675 disponibles. El selector depende de `.tpv`, de modo que la escala se revierte sola al salir de la caja o al cambiar de pestaña (`KeepAlive` desmonta el DOM y conserva la venta).
- **Rationale del escalado frente a media queries**: recortar alturas y tipografías por separado desequilibraba el conjunto (botones grandes con texto pequeño), que es justo el efecto de "sobredimensionado" que se quería evitar. Solo se mantiene una media query de **proporción**, no de tamaño: por debajo de 1200 px el ticket pasa del 46% al 40% del ancho.
- **Degradación**: `col-acciones` mantiene `overflow: auto`; si un teclado con muchas filas no cupiese, aparece scroll en esa columna en lugar de cortarse el pad.
- **Requisito de plataforma**: `:has()` necesita Chromium ≥ 105; Electron 35 (Chromium 134) lo cumple.

## R-009 — Offline-first (Principio II)

- **Decision**:
  - **SQLite** vive en **`descartes-electron`** (`better-sqlite3` o equivalente), no en SQL Server.
  - Tablas locales: `tpv_draft`, `tpv_sync_queue`, `tpv_offline_meta` (último snapshot teclado/artículos).
  - Flujo cobro offline: persistir venta cerrada en SQLite + imprimir ticket local → encolar payload → worker sync `POST /api/tpv/sync/ventas` con **`idempotencyKey`** (UUID).
  - API sync: transacción idempotente; si `idempotencyKey` ya procesada → 200 con referencia existente.
  - Numeración offline: reservar bloque de tickets vía `POST /api/tpv/reservar-numeros` cuando hay red **al abrir TPV**; si no hay reserva previa, usar numeración temporal `OFF-{uuid}` y reconciliar en sync (documentado en data-model).
- **Stock offline**: **vender con aviso suave** si stock remoto desconocido; no bloquear cobro (spec pregunta 4).
- **Alternatives considered**: IndexedDB solo en browser (sin garantías Electron); sync directo sin idempotencia (riesgo duplicados).

## R-010 — Impresión ticket

- **Decision**: Reutilizar flujo Gestión → `useImpresionVentaDocumento` / API ticket + `window.descartes.printTicket`. Offline: generar HTML/ESC-POS desde plantilla tickets cacheada en Electron.
- **Rationale**: Infraestructura ya probada en etiquetas/ventas.

## R-011 — Escáner

- **Decision**: Reutilizar `createBarcodeScanWatcher` (patrón `VentaDetalleView`) en TPV; mismo resolver `resolverArticulo`.
- **Rationale**: HID como teclado; cero integración extra v1.

## R-012 — Esquema SQL Server

- **Decision**: **Sin ALTER** en tablas legacy para MVP. Cola sync e idempotencia: tabla opcional `TpvSyncLog` **solo si** hace falta auditoría server-side; preferir tabla nueva justificada vs tocar `AlbaranesVentasCab`.
- **Propuesta mínima server** (si idempotencia en SQL):

```sql
-- Opcional fase sync; justificar en implementación
CREATE TABLE TpvSyncLog (
  IdempotencyKey uniqueidentifier NOT NULL PRIMARY KEY,
  Empresa nvarchar(3) NOT NULL,
  Albaran int NOT NULL,
  Tipo nvarchar(1) NOT NULL,
  CreatedAt datetime NOT NULL DEFAULT GETDATE()
);
```

- **Alternatives considered**: Columna nueva en cabecera venta (rompe IV).

## R-013 — Botones especiales `Teclados.Tcl_*` (v1 reducido)

| Tcl (legacy) | Acción TPV v1 |
|--------------|----------------|
| `Tcl_Cliente` | Abrir buscador cliente |
| `Tcl_Borra` / `Tcl_Borrar` | Borrar línea seleccionada |
| `Tcl_Descuento` | Descuento línea (permiso editar) |
| `Tcl_ConsArticulo` | Modal consulta artículo |
| `Tcl_NoVenta` | Cancelar ticket en curso |
| Resto (mesa, bar, cocina…) | **Ignorar** en v1 Garden |

- Mapeo exacto Tcl → número de tecla en `DefPlus` se documentará al implementar (legacy asigna teclas especiales en diseñador).

## Cierre de open questions (spec.md)

| # | Resolución |
|---|------------|
| 1 | `H_GENERAL` = teclado zero-padded a 3 chars (R-001). |
| 2 | Incrementar cantidad misma línea (R-004). |
| 3 | Solo Ticket en MVP; Factura fase 2 (R-005). |
| 4 | Stock offline: aviso suave, no bloqueo (R-009). |
| 5 | UI en `/tpv` dentro de gestión (R-008). |
