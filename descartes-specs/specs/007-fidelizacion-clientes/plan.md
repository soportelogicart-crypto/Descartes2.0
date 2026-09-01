# Implementation Plan: 007-fidelizacion-clientes

**Branch**: `007-fidelizacion-clientes`  
**Spec**: [spec.md](./spec.md) · **Research**: [research.md](./research.md) · **Data**: [data-model.md](./data-model.md)  
**Date**: 2026-09-01  
**Status**: Fase 2 implementada — migración 009 aplicada en BD dev; motor en `FidelizacionService` + hook en `VentaEscrituraService::finalizar`.

## Summary

Política de fidelización **por tienda**: la tienda elige un código del maestro
`TiposCalculoFidelizacion`; el **motor** de esa fila acumula al finalizar. Canje fuera de este plan.

## Phases

1. ✅ **Catálogo + tienda (US1–US2)** — tabla + ALTER + semilla `EUROS`/`PUNTOS`, entidad mantenimiento, desplegable en Tiendas, ficha Cliente según motor. **Sin tocar cobro.**
2. ✅ **Motor (US3)** — `FidelizacionService`; cierre resuelve código → motor. Abono vía finalizar del documento negativo. Anulación de documento cerrado pendiente (sin escritura en `LogAnulaciones`).
3. **TPV visible (US4)** — saldo en cabecera al asignar cliente.
4. Polish / smoke / OpenAPI.

## Constitution check

| Principio | Estado |
|-----------|--------|
| I. API única | ✅ Cierre vía `descartes-api` |
| II. Offline TPV | ⚠ Motor v1 online; misma función cuando exista sync 006 |
| III. Una SQL por cliente | ✅ Parámetro en `Empresas` de esa BD |
| IV. Legacy + ALTER justificada | ✅ Spec D3 / FR-011 |
| V. Fiscal | ✅ No altera Facturas / TBAI; solo `Clientes` |
| VI. Permisos | ✅ Reuse tiendas / clientes / ventas |
| VII–IX | ✅ PHP + Vue existentes |

## Next

Fase 3: saldo de fidelización visible en cabecera TPV al asignar cliente (US4).
Canje = spec 008 (o ampliación).
