# Research: 004-compras-gestion

**Feature**: Módulo de Compras (Gestión)  
**Date**: 2026-08-12  
**Spec**: [spec.md](./spec.md)  
**Sources**: `db/script.sql`, `tiendas-fields.php` / `tiendas-tabs.ts` (contadores), `ArticuloStockRepository`, patrón Ventas

## R-001 — Contadores de numeración (FR-004)

- **Decision**: Numerar por **tienda** (`Empresas.Codigo` = `Empresa` en cabeceras):
  | Documento | Contador en `[Empresas]` | API / UI tiendas |
  |-----------|--------------------------|------------------|
  | Pedido a proveedor | `UltPedidoCom` | `ultPedidoCom` («Pedidos») |
  | Albarán de compra | `UltAlbaranCom` | `ultAlbaranCom` («Albaran Compra») |
  | Albarán devolución compra | `UltAlbaranDevCom` | *columna existe; no está en modal de contadores UI hoy* |
- **Rationale**: Contadores ya mapeados en ficha tienda y visibles en Mantenimiento. Misma semántica que ventas (`UltAlbaranVen`, `UltPedidoCli`): incrementar al crear y persistir en la misma transacción.
- **Alternatives considered**: Contador global por BD (rompe multi-tienda); `MAX(Albaran)+1` sin actualizar `Empresas` (desincroniza contadores de 1.0).
- **Follow-up**: Exponer `ultAlbaranDevCom` en contadores de tienda (solo lectura) para transparencia; al crear devolución usar `UltAlbaranDevCom` **o** el mismo `UltAlbaranCom` según comportamiento real 1.0 (validar en BD de prueba: ¿devoluciones comparten secuencia?).

## R-002 — Tablas núcleo (sin schema nuevo)

- **Decision**:
  - Albarán compra = `AlbaranesCompraCab` + `AlbaranesComprasLin` — PK `(Empresa, Albaran)`.
  - Pedido proveedor = `PedidosCab` + `PedidosLin` — PK `(Empresa, Pedido)`.
  - Factura proveedor = `FacturasCompras` — PK `(Factura)` (**sin** `Empresa` en PK).
  - Maestro = `Proveedores` (ya en Mantenimiento; soft-delete `Baja` vía migración 001).
- **Rationale**: Principio IV; espejo de Ventas sobre tablas legacy.
- **Alternatives considered**: Vistas materializadas (innecesarias); tablas `_2`/shards (fuera de alcance).

## R-003 — Flags de cabecera de albarán (bloqueo / stock)

Campos relevantes en `AlbaranesCompraCab`:

| Campo | Tipo | Interpretación provisional |
|-------|------|----------------------------|
| `Actualizado` | bit | Stock ya aplicado (entrada hecha). Default 0. |
| `TrasCtb` | bit | Traspasado a contabilidad → **no editable**. |
| `TrasModem` | bit | Comunicado / modem → tratar como bloqueo suave (solo lectura salvo permiso especial). |
| `AlbaranDevolucion` | bit | Documento de devolución a proveedor. |
| `AlbaranDevolucionEstado` | int | Estado interno devolución (muestrear valores en BD). |
| `Estado` | nvarchar(2) | Código legado corto; **muestrear `DISTINCT Estado`** en research de implementación. |
| `Seleccion` | bit | Marca de selección en listados masivos 1.0. |
| `SuAlbaran` | nvarchar(20) | Nº documento del proveedor. |

- **Decision (edición)**:
  - Editable si `TrasCtb = 0` y (recomendado) `Actualizado = 0`, o con flujo «desactualizar stock → editar → re-actualizar».
  - Si `Actualizado = 1`, v1 MUST NOT permitir cambiar cantidades/artículos sin revertir stock (evitar descuadre).
  - `TrasCtb = 1` → solo lectura siempre en v1.
- **Rationale**: Evita stock fantasma; alineado a flag explícito en cabecera (a diferencia de ventas, donde el rebaje va por línea `RebajeStock`).
- **Alternatives considered**: Editar siempre y recalcular stock al vuelo (más complejo, más riesgo); ignorar `Actualizado` (rompe 1.0).

## R-004 — Stock al «hacer entrada» / `Actualizado`

- **Decision**: Al pasar `Actualizado` de 0 → 1 (acción «Actualizar stock» / al finalizar entrada):
  1. Para cada línea con artículo válido (no vacío / no comentario):
     - Upsert en `[Stock]` clave `(Codigo, Almacen, Año, Mes)` del almacén del albarán (o `Lin.Almacen` si > 0).
     - Incrementar `Entradas` (+ `ValorEntradas` = cantidad × precio neto línea, regla a calibrar).
     - Devolución (`AlbaranDevolucion = 1` o cantidad &lt; 0): restar entradas o sumar `Salidas` según política 1.0 (**validar en prueba**; hipótesis inicial: cantidad negativa sobre `Entradas` o flag devolución → `Salidas`).
  2. Marcar `Actualizado = 1`.
  3. Todo en **una transacción**.
- **Lectura de stock existente**: `ArticuloStockRepository` ya usa  
  `Entradas - Salidas - Ventas + TraspasosEntradas - TraspasosSalidas`.
- **Rationale**: Tabla `Stock` es el acumulado mensual por almacén; las compras alimentan `Entradas`.
- **Alternatives considered**: Solo marcar `Actualizado` sin tocar `Stock` (rompe inventario); movimiento en tabla auxiliar inexistente (no hay en script).
- **Riesgo**: El código 1.0 (VB/Delphi) no está en el monorepo; la primera implementación MUST contrastarse con un albarán real antes/después en BD de prueba.

## R-005 — Líneas y vínculo pedido

- **Decision**: `AlbaranesComprasLin.Pedido` enlaza al pedido proveedor. Campos línea: `Articulo`, `Descripcion`, `Cantidad`, `Precio`, `PjeDto`, `Dto1..3`, `Lote`, `Almacen`, `ArticuloOriginal`.
- **PedidosLin**: `CantidadPed` / `CantidadSer` / `PrecioPed` / `PrecioRec`; recepción incrementa `CantidadSer` y no debe superar pendiente.
- **`PedidosCab.Situacion`**: `smallint` — valores exactos a muestrear; UI: pendiente / parcial / servido derivados de líneas si el código es ambiguo.
- **Rationale**: Índice `KEmpresaPedido` en líneas de compra confirma el enlace.
- **Alternatives considered**: Tabla puente pedido↔albarán (no existe).

## R-006 — Facturas de proveedor (v1 consulta)

- **Decision**: Solo lectura de `FacturasCompras`. Contador: **no** hay `UltFacturaCom` en `Empresas`; PK es `Factura` global. Alta v1 fuera de alcance (spec FR-009).
- **Rationale**: Sin contador de tienda claro; vencimientos ya en columnas `FechaVto*` / `ImporteVto*`.
- **Alternatives considered**: Usar `UltFactura` de ventas (incorrecto semánticamente); inventar contador nuevo (aplazar a fase 2 con alta).

## R-007 — Forma de la API

- **Decision**: Rutas dedicadas `/api/compras/...` (como ventas), no CRUD genérico de mantenimiento.
  - Ejemplos: `GET/POST /api/compras/albaranes`, `GET/PUT .../albaranes/{empresa}/{albaran}`, `POST .../albaranes/{…}/actualizar-stock`, `GET/POST /api/compras/pedidos`, `POST .../pedidos/{…}/recibir`, `GET /api/compras/facturas`.
- **Rationale**: PK compuestas, acciones de dominio (stock, recepción), mismos middlewares auth/permisos.
- **Alternatives considered**: Entity CRUD (no cubre recepción ni stock).

## R-008 — Permisos

- **Decision (v1)**: Un módulo lógico **`compras`** con acciones `ver` / `crear` / `editar` / `eliminar`:
  - `ver` → listados y detalle (albaranes, pedidos, facturas).
  - `crear` → alta albarán / pedido.
  - `editar` → modificar documentos no bloqueados + actualizar stock + recepción desde pedido.
  - `eliminar` → borrar solo si `Actualizado = 0` y `TrasCtb = 0` (y sin líneas servidas críticas).
- **Rationale**: Igual que ventas MVP (R-008 de 002); menos fricción en Roles. Submódulos (`compras-albaranes`, …) posibles en fase 2 si negocio lo pide.
- **Alternatives considered**: Tres módulos ya en v1 (sobreingeniería).

## R-009 — Frontend

- **Decision**: Sustituir placeholder `/compras` por layout + subrutas; patrón listado + ficha + toolbar como Ventas; impresión A4 reutilizando preview + impresoras Generales II (`formatoAlbaranCompras`, `impAlbaranCompras`, etc.).
- **Rationale**: Menú ya tiene sección compras; Principio VIII.
- **Alternatives considered**: Meter bajo Mantenimiento (confunde dominio).

## R-010 — Proveedores y artículos

- **Decision**: Búsqueda de proveedores vía API mantenimiento existente (`activo` / no baja). Respetar `Articulos.BloqueoCompra` / `Articulos2.BloqueadoCompra` al añadir líneas (rechazar o avisar).
- **Nota**: `Proveedores.PedidosAutomaticos` existe en legacy (pedidos por stock/mínimos) — **distinto** del estudio IA de lectura de facturas; no confundir en UI.
- **Rationale**: Maestros ya hechos; bloqueo compra es regla de catálogo.

## R-011 — Esquema / migraciones

- **Decision**: Sin ALTER obligatorio para MVP de pantallas. Opcional: añadir `ultAlbaranDevCom` al mapeo/UI de contadores (solo lectura).
- **Rationale**: Principio IV.
- **Alternatives considered**: Tabla de adjuntos para estudio IA (solo cuando se abra ese research).

## R-012 — Estudio IA (recordatorio)

- Documentado en spec sección «Estudio (no MVP)». No genera tareas de implementación en v1.
- **T045 verificado 2026-08-12**: ningún ítem de código OCR/IA en `tasks.md`; sin archivo `research-importacion-documento-proveedor.md` aún; sin endpoints/UI de importación automática. El research dedicado queda *(más adelante)* en `plan.md`.
- Hosting: API propia + proveedor nube; sin Node en hosting web.

## Clarificaciones propuestas a Open Questions del spec

| # | Pregunta | Propuesta research |
|---|----------|-------------------|
| 1 | Contador albarán | **`Empresas.UltAlbaranCom`** (+ `UltPedidoCom`; devoluciones: validar `UltAlbaranDevCom`) |
| 2 | Borrado | Físico solo si `Actualizado=0` y `TrasCtb=0`; si no, denegar |
| 3 | Facturas alta | **Solo consulta en v1** |
| 4 | Permisos | **Un módulo `compras`** |
| 5 | Estudio IA | Tras estabilizar albaranes/pedidos manuales |

## Riesgos abiertos (validar en BD/oficina)

1. Comportamiento exacto 1.0 al poner `Actualizado=1` (¿también precio medio artículo? ¿`ProvCompras` acumulado mes?).
2. Códigos de `PedidosCab.Situacion` y `AlbaranesCompraCab.Estado`.
3. Devoluciones: ¿misma secuencia `UltAlbaranCom` o `UltAlbaranDevCom`?
4. Si ventas ya escriben `Stock.Ventas` en algún job aparte, no duplicar lógica; compras solo `Entradas`.
