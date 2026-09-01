# Research: Fidelización de clientes

**Feature**: `007-fidelizacion-clientes`  
**Date**: 2026-09-01

## R-001 — Dos acumuladores en `Clientes`

**Hallazgo**: El mapeo 2.0 (`clientes-fields.php`) expone en paralelo:

- `PjeFidelizacion` + `AcumuladoFidelizacion` → saldo en euros (cashback %)
- `AcumuladoPuntos` → puntos

No hay motor en `VentaEscrituraService`. Los acumulados en UI son `readOnly`.

**Decisión**: Tratarlos como **dos métodos**; una tienda elige uno (spec D1–D3).

## R-002 — Catálogo extensible, no enum en `Empresas`

**Hallazgo**: `sql/Empresas.sql` solo tiene `MinimoFidelizacion` y `BloqueoFidelizacion`.
Ningún `TipoCalculo*`. Un `nchar(1)` `N`/`E`/`P` obliga a ALTER (o a reciclar letras) cada
formato nuevo.

**Patrón 2.0**: maestros con código corto + `Baja` (Impuestos, FormasPago, `TipoDescuento` de 6
caracteres en cliente). La tienda **apunta** a un código; la matemática vive en un **motor**
registrado en API.

**Decisión**:

1. Tabla `TiposCalculoFidelizacion` (`Codigo`, `Nombre`, `Motor`, `Factor`, `Configuracion`, `Baja`).
2. `Empresas.TipoCalculoFidelizacion nvarchar(20)` vacío = no acumular.
3. Tres capas: **código comercial** (el garden lo nombra) ≠ **motor** (estrategia) ≠ **factor**
   / **configuración JSON** (parámetros). «El doble de puntos» es otra fila, no otro motor.

**Descartado**: enum `N`/`E`/`P` en `Empresas`; inferir el método mirando acumulados de clientes.

**Formatos futuros (ejemplos, no v1)**: sellos/N compras, puntos por artículo, tramos de
importe → nuevo valor en el registro de motores + INSERT; sin tocar `Empresas`.

## R-003 — Quién elige el método

**Hallazgo**: Principio III (una SQL por cliente Descartes). `Usuarios` no tiene campos de
fidelización. El % sí es por socio.

**Decisión**: Interruptor en **tienda**. El cajero no elige. El socio solo aporta % / tarjeta.

## R-004 — Dónde calcular

**Hallazgo**: Cierre único ya usado por Gestión y TPV: finalizar venta, abono, anulación TPV.

**Decisión**: Enganchar ahí; no endpoint `/api/fidelizacion/acumular` suelto.

## R-005 — Canje y monedero

**Hallazgo**: `FormasPago.TarjetaMonedero`, `TipoDescuentoFidelizacion`, módulo `Vales`.

**Decisión**: Fuera de v1 (spec D7 / FR-009).

## R-006 — Base y mínimo

**Propuesta v1** (sin código 1.0 en el repo):

- Base = suma de importes de línea con dto, IVA según ya esté el importe de línea de venta
  (mismo `importe` que ve el ticket).
- Excluir `Articulos.BloqueoFidelizacion`.
- Si base < `MinimoFidelizacion` → 0.
- Abono: misma fórmula con importe negativo de las líneas abonadas.

Pendiente de contrastar con una venta real 1.0 si el mínimo es por ticket o por línea.

## Open questions

- ¿El 1.0 usa IVA incluido o base imponible? Seguir el importe de línea de `AlbaranesVentasLin`.
- ¿Factura de albaranes ya acumulados? Spec: no duplicar.
