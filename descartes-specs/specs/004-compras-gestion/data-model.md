# Data Model: 004-compras-gestion

**Feature**: Módulo de Compras (Gestión)  
**Date**: 2026-08-12  
**Source**: `db/script.sql` + [research.md](./research.md) — sin migraciones nuevas en MVP (salvo opcional contador UI).

## Principio de mapeo

- API/UI: **camelCase** JSON.
- SQL: nombres legacy.
- `Empresa` = **tienda** (`Empresas.Codigo`).
- Escritura: albaranes compra, pedidos proveedor, stock (`Actualizado`), contadores tienda. Facturas compra: **solo lectura** v1.

---

## 1. Albarán de compra

### Tablas

| Rol | Tabla | PK |
|-----|-------|-----|
| Cabecera | `AlbaranesCompraCab` | `(Empresa, Albaran)` |
| Líneas | `AlbaranesComprasLin` | `(Empresa, Albaran, NroLin)` |

### Cabecera (API ↔ SQL)

| API | SQL | Notas |
|-----|-----|-------|
| empresa | Empresa | Tienda |
| albaran | Albaran | Numeración `UltAlbaranCom` |
| suAlbaran | SuAlbaran | Nº documento proveedor |
| fechaAlbaran | FechaAlbaran | |
| proveedor | Proveedor | FK lógica `Proveedores.Codigo` |
| fpago | FPago | Forma de pago |
| importeAlb | ImporteAlb | Total documento |
| importeDtos | ImporteDtos | |
| importeIva | ImporteIVA | |
| importeRec | ImporteRec | Recargo |
| observaciones | Observaciones | ntext |
| actualizado | Actualizado | bit — stock aplicado |
| albaranDevolucion | AlbaranDevolucion | bit |
| albaranDevolucionEstado | AlbaranDevolucionEstado | int |
| trasModem | TrasModem | bit |
| trasCtb | TrasCtb | bit — bloqueo duro |
| almacen | Almacen | smallint |
| serie | Serie | |
| seleccion | Seleccion | bit UI legado |
| cliente | Cliente | opcional legado |
| proyecto | Proyecto | |
| importeTransporte | ImporteTransporte | |
| coeficienteTransporte | CoeficienteTransporte | |
| brutoConTransporte | BrutoConTransporte | |
| estado | Estado | nvarchar(2) — muestrear valores |
| lUpdate | LUpdate | |

### Línea

| API | SQL | Notas |
|-----|-----|-------|
| nroLin | NroLin | IDENTITY |
| articulo | Articulo | |
| descripcion | Descripcion | |
| cantidad | Cantidad | |
| precio | Precio | |
| pjeDto | PjeDto | |
| dto1 / dto2 / dto3 | Dto1 / Dto2 / Dto3 | |
| pedido | Pedido | Pedido proveedor origen (0 = ninguno) |
| lote | Lote | |
| almacen | Almacen | Si 0 → usar cabecera |
| articuloOriginal | ArticuloOriginal | |

### Reglas

- Alta: `Albaran = UltAlbaranCom + 1` (tienda), `Actualizado = 0`.
- Editable si `TrasCtb = 0` y preferiblemente `Actualizado = 0`.
- Acción `actualizarStock`: ver research R-004 → `Stock.Entradas` + `Actualizado = 1`.
- Borrado: solo si `Actualizado = 0` y `TrasCtb = 0`.

### Relaciones

- N:1 proveedor, almacén, tienda.
- 0:N líneas → 0:1 pedido (`Pedido`).

---

## 2. Pedido a proveedor

### Tablas

| Rol | Tabla | PK |
|-----|-------|-----|
| Cabecera | `PedidosCab` | `(Empresa, Pedido)` |
| Líneas | `PedidosLin` | `(Empresa, Pedido, Articulo, NumLin)` *ver nota* |

\* PK legacy incluye `Articulo`; en API exponer `numLin` como identidad de línea. Evitar duplicar mismo artículo+numLin.

### Cabecera

| API | SQL | Notas |
|-----|-----|-------|
| empresa | Empresa | |
| pedido | Pedido | `UltPedidoCom + 1` |
| fechaPedido | FechaPedido | |
| proveedor | Proveedor | |
| importe | Importe | |
| situacion | Situacion | smallint — derivar UI si hace falta |
| fechaMaxRecepcion | FechaMaxRecepcion | |
| observaciones | Observaciones | |
| observInternas | ObservInternas | |
| vendedor | Vendedor | |
| almacen | Almacen | |
| trasModem | TrasModem | |
| preciosActualizados | PreciosActualizados | |
| previsionC | PrevisionC | |

### Línea

| API | SQL |
|-----|-----|
| numLin | NumLin |
| articulo | Articulo |
| descripcion | Descripcion |
| cantidadPed | CantidadPed |
| cantidadSer | CantidadSer |
| precioPed | PrecioPed |
| precioRec | PrecioRec |
| pjeDto | PjeDto |
| dto1 / dto2 / dto3 | Dto1 / Dto2 / Dto3 |
| importe | Importe |
| almacen | Almacen |
| prevision | Prevision |

### Situación derivada (UI)

```
pendiente  = Σ(CantidadSer) ≈ 0
parcial    = 0 < Σ(CantidadSer) < Σ(CantidadPed)
servido    = Σ(CantidadSer) ≥ Σ(CantidadPed)  (ε float)
```

Actualizar `Situacion` numérico al recibir según códigos observados en BD.

### Recepción → albarán

1. Crear `AlbaranesCompraCab` + líneas con `Pedido` = nº pedido.
2. Incrementar `CantidadSer` en cada línea recibida (≤ pendiente).
3. Recalcular situación pedido.

---

## 3. Factura de proveedor (consulta v1)

### Tabla

| Tabla | PK |
|-------|-----|
| `FacturasCompras` | `(Factura)` |

### Campos principales

| API | SQL |
|-----|-----|
| factura | Factura |
| suFactura | SuFactura |
| fecha | Fecha |
| proveedor | Proveedor |
| estado | Estado |
| fpago | FPago |
| fechaVto1…6 | FechaVto1…6 |
| importeVto1…6 | ImporteVto1…6 *(nota: ImporteVto31 en SQL)* |
| estadoVto1…6 | EstadoVto1…6 |
| baseImp1…3 | BaseImp1…3 |
| pjeIva1…3 | PjeIVA1…3 |
| pjeRec1…3 | PjeRec1…3 |

Sin alta ni contador de tienda en v1.

---

## 4. Stock (efecto colateral)

| Tabla | PK |
|-------|-----|
| `Stock` | `(Codigo, Almacen, Año, Mes)` |

| Campo | Uso en compras |
|-------|----------------|
| Entradas | +cantidad al actualizar albarán |
| ValorEntradas | +cantidad × precio neto (calibrar) |
| Salidas | posible uso en devoluciones (validar 1.0) |

Año/Mes = de `FechaAlbaran` (o fecha sistema si null).

---

## 5. Contadores tienda

| Contador SQL | API tienda | Documento |
|--------------|------------|-----------|
| UltPedidoCom | ultPedidoCom | Pedido proveedor |
| UltAlbaranCom | ultAlbaranCom | Albarán compra |
| UltAlbaranDevCom | ultAlbaranDevCom | Devolución compra (UI contadores solo lectura) |

Actualizar en la misma transacción que el INSERT del documento.

---

## 6. Entidades de soporte (solo lectura / selección)

| Entidad | Tabla | Uso |
|---------|-------|-----|
| Proveedor | Proveedores | Buscar / validar; respetar Baja |
| Artículo | Articulos (+ Articulos2) | Líneas; `BloqueoCompra` / `BloqueadoCompra` |
| Almacén | Almacenes | Cabecera / línea |
| Forma pago | FormasPago | `FPago` cabecera |

---

## 7. Permisos

Módulo `compras` en Roles:

| Acción | Capacidades |
|--------|-------------|
| ver | Listados y detalle |
| crear | Alta albarán / pedido |
| editar | Editar no bloqueados, actualizar stock, recepción |
| eliminar | Borrar albarán/pedido según reglas R-008 |

---

## State transitions (resumen)

```
Albarán:  borrador (Actualizado=0, TrasCtb=0)
       →  stock actualizado (Actualizado=1)
       →  contabilidad (TrasCtb=1) [fuera escritura v1]

Pedido:   abierto → parcial → servido  (vía CantidadSer / Situacion)
```
