# Data Model: 002-ventas-gestion

**Feature**: Módulo de Ventas (Gestión)  
**Date**: 2026-07-22  
**Source**: `db/script.sql` (legado Descartes 1.0) — sin migraciones nuevas en MVP.

## Principio de mapeo

- API y UI usan **camelCase** en JSON.
- SQL conserva nombres legacy.
- `Empresa` en tablas = **código de tienda** (fila de `[Empresas]`), no «empresa cliente» multi-tenant.
- Sin tablas nuevas. Escritura limitada a `Vales` (emisión/liquidación) y `PedidosClientes` + `PedidosClientesLin` (alta / Impreso).

---

## 1. Venta (consulta)

### Tablas

| Rol | Tabla | PK |
|-----|-------|-----|
| Cabecera | `AlbaranesVentasCab` | `(Empresa, Tipo, Albaran)` |
| Líneas | `AlbaranesVentasLin` | `(Empresa, Tipo, Albaran, NroLin)` |

### Campos cabecera (API ? SQL)

| API | SQL | Notas |
|-----|-----|-------|
| empresa | Empresa | Tienda |
| tipo | Tipo | Discriminador ticket/albarán |
| albaran | Albaran | Número |
| puesto | Puesto | Caja / «registradora» |
| cliente | Cliente | |
| razonSocial | RazonSocial | |
| fecha | Fecha | Fecha/hora venta |
| vendedor | Vendedor | |
| estado | Estado | |
| sesion | Sesion | |
| importe | Importe | |
| anulado | Anulado | Si existe en cab |
| facturada | (derivado) | true si Factura no nulo |
| facturaTipo / factura | FacturaTipo / Factura | Vínculo factura |
| pedido | Pedido | Pedido origen si aplica |
| fpago1/2 (+3 si hay) | Fpago1/2… | Formas de pago |
| impFpago1/2 | ImpFpago1/2 | Importes por forma |
| importeBase1… / pjeIva1… / importeIva1… | ImporteBaseN, PjeIvaN, ImporteIvaN | Hasta 4–6 slots IVA |
| impreso | Impreso | Indicador impresión TPV |

### Campos línea

| API | SQL |
|-----|-----|
| nroLin | NroLin |
| articulo | Articulo |
| cantidad | Cantidad |
| precio | Precio |
| pjeDto | PjeDto |
| importe | Importe |
| pjeIva | PjeIva |

### Validaciones (Gestión)

- Solo lectura: no CREATE/UPDATE de importes ni líneas de venta cerrada.
- Filtros: fecha, empresa/puesto, vendedor, cliente, estado.

### Relaciones

- N:1 cliente, vendedor, puesto/tienda.
- 0:1 factura (`Facturas`).
- 0:1 pedido cliente.
- N:1 sesión (`Sesiones`).

---

## 2. Sesión de caja

| Tabla | PK | Uso |
|-------|-----|-----|
| `Sesiones` | `(Empresa, Puesto, Sesion)` | Cabecera sesión: `Cerrada`, `Arqueo`, contadores cobros/pagos/vales/anulaciones, efectivo |

---

## 3. Arqueo / desglose

| Tabla | PK | Uso |
|-------|-----|-----|
| `Arqueo` | `(Empresa, Puesto, Sesion, Codigo)` | `Codigo` = forma de pago; `Acumulado`, `Entrado`; `Moneda01`…`Moneda20` |

### Regla de cuadre (FR-006)

```
totalArqueo(sesión, puesto) =
  ? Arqueo.Acumulado (o Entrado según criterio TPV documentado en implementación)
  WHERE FormasPago.CobroDeArqueo = 1
```

Solo filas cuyo `Codigo` tenga `CobroDeArqueo`. Desglose = vector `Moneda01`…`Moneda20` (máx. 20).

«Registradora» = mismo recurso filtrado por `Puesto`.

---

## 4. Anulación (diario, solo lectura)

| Tabla | Uso |
|-------|-----|
| `LogAnulaciones` | Artículo, cantidad, importes, motivo, cajero, mesa/puesto, fecha, sesión, albarán, usuario |
| `Motivos` | Catálogo opcional para etiqueta de `Motivo` |

Sin INSERT/UPDATE desde Gestión (FR-021).

---

## 5. Movimiento cobro/pago (vista lógica)

No hay tabla propia. Cada slot `FpagoN` + `ImpFpagoN` de `AlbaranesVentasCab` es un movimiento:

| Campo lógico | Origen |
|--------------|--------|
| tipo | `FormasPago.CobroPago` ? Cobro / Pago |
| formaPago | FpagoN |
| importe | ImpFpagoN |
| fecha | Fecha / FechaCobro |
| puesto | Puesto |
| venta | (Empresa, Tipo, Albaran) |

---

## 6. Forma de pago (maestro, prerrequisito)

| Tabla | Campos críticos |
|-------|-----------------|
| `FormasPago` | `Codigo`, `Descripcion`, **`CobroDeArqueo`**, **`CobroPago`**, `Vales`, `Baja` |

Extender entity `formas-pago` en mantenimiento para mapear `cobroDeArqueo`, `cobroPago`.

Convención UI inicial: `C`?Cobro, `P`?Pago (validar en BD).

---

## 7. Vale / bono

| Tabla | PK |
|-------|-----|
| `Vales` | `(Empresa, Codigo)` |

| API | SQL | Notas |
|-----|-----|-------|
| codigo | Codigo | |
| tipo / numero | Tipo / Numero | |
| liquidado | Liquidado | bit |
| fecha | Fecha | Emisión |
| fechaCaducidad | FechaCaducidad | |
| fechaLiquidacion | FechaLiquidacion | |
| tipoLiquidacion | TipoLiquidacion | nchar(1) |
| importe | Importe | |
| cliente | Cliente | |
| puesto / sesion / cajero | Puesto / Sesion / Cajero | |
| formaPago | FormaPago | |
| albaran | Albaran | Si ligado a venta |

### Estados / transiciones

```
(pendiente: Liquidado=0, no caducado)
    --emitir--> pendiente
    --liquidar(fecha, tipoLiquidacion)--> liquidado
(liquidado) --X--> no reliquidar
(caducado: fechaLiquidacion_día > FechaCaducidad) --X--> no liquidar
```

Caducidad: caducado si fecha de liquidación (día calendario) **>** `FechaCaducidad`.

### Validaciones escritura

- Emitir: cliente, importe > 0, caducidad ? hoy (o regla negocio).
- Liquidar: no liquidado; no caducado; permiso `editar` módulo ventas.

---

## 8. Pedido de cliente

| Tabla | PK |
|-------|-----|
| `PedidosClientes` | `(Empresa, Pedido)` |
| `PedidosClientesLin` | `(Empresa, Pedido, NroLin)` |

| API cabecera | SQL |
|--------------|-----|
| pedido | Pedido |
| situacion | Situacion |
| estado | Estado |
| impreso | Impreso |
| cliente, razonSocial, fecha, vendedor, puesto | homónimos |
| importe (+ bases/IVA) | homónimos |

| API línea | SQL |
|-----------|-----|
| articulo | Articulo |
| cantidadPedida | CantidadPedida |
| precio | Precio |
| importe | Importe |

### Transiciones relevantes MVP

- Alta cabecera + ?1 línea ? estado/situación inicial legado.
- Marcar impreso: `Impreso = 1` (permiso editar).
- Derivación a venta: informativa (`AlbaranesVentasCab.Pedido`); cobro en TPV.

No usar `PedidosCab`/`PedidosLin` (pedidos a proveedor).

---

## 9. Permisos

Módulo canónico: **`ventas`** (ya en roles).

| Acción | Uso en este módulo |
|--------|--------------------|
| ver | Acceso a todos los submenús de consulta |
| crear | Alta pedidos; emisión de vales |
| editar | Liquidar vales; marcar pedido Impreso |
| eliminar | No expuesto en MVP |

---

## 10. Extensiones de esquema

**Ninguna en MVP.** Si tras medir hace falta índice en `AlbaranesVentasCab(Fecha, Puesto)` u otro, documentar migración justificada en follow-up (Principio IV).
