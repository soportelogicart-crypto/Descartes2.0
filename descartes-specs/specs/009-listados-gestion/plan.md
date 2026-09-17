# Implementation Plan: Módulo de Listados (Gestión)

**Branch**: `009-listados-gestion` | **Date**: 2026-09-16 | **Spec**: [spec.md](./spec.md)

## Summary

Sustituir el placeholder de `/listados` por un **hub buscable** y, informe a informe, una **plantilla común** (filtros → Generar → grid → Excel / PDF / imprimir). Reutilizar pantallas ya hechas (ABC, diario de facturación, arqueo…) vía catálogo declarativo. API PHP bajo `/api/listados/*` (o endpoints existentes); **sin** Crystal ni `Informes.mdb`.

## Fases

| Fase | Entrega | Estado |
|------|---------|--------|
| **0** | `research.md`, `spec.md`, este `plan.md`, `tasks.md` | Hecho |
| **1 — Hub (P1)** | `listados-nav.ts`, `ListadosHubView`, permiso `listados`, recientes | **Hecho en código** |
| **2 — Shell informe** | Componente/layout compartido: fechas, tienda, Más filtros, Generar, export, preview A4 listado | Hecho (T010 refactor opcional) |
| **3 — Stock MVP** | API + ficha `stock` con `agruparPor` | Hecho |
| **4 — Resto v1** | Informes v1 (stock, mínimos, IVA, tickets, extracto clientes) | Hecho |
| **5 — ABC UX** | Compactar rangos ABC en Más filtros (mismo endpoint) | Hecho |
| **6 — P2 mantenimientos** | Botón Listado = export grid filtrado (no `window.print`) | Hecho |

## Technical context

- **Frontend**: Vue 3 en `descartes-gestion`; catálogo en `src/config/listados-nav.ts`.
- **Backend**: Slim en `descartes-api`; nuevos servicios en `Services/Listados/` (propuesto).
- **Permisos**: Hub `listados`; cada ítem reutiliza módulo existente (`ventas-abc`, `facturacion-diario`, …).
- **Excel**: CSV UTF-8 BOM, `;`, decimal `,` (igual que Diario facturación).
- **PDF listados**: layout fijo cabecera + tabla (no plantillas documento A4).

## Constitution check

| Principio | Estado |
|-----------|--------|
| I. Sin SQL desde Vue | PASS — solo API |
| III. Empresa / tienda | PASS — filtros acotados como resto 2.0 |
| IV. Sin tablas catálogo SQL v1 | PASS — catálogo en TS |
| VI. Permisos | PASS — hub + módulo por informe |
| VIII. Frontend simple | PASS — hub tipo Configuración; reutilizar grids |

## Estructura prevista (API)

```text
descartes-api/src/
  Routes/listados.php
  Http/Controllers/ListadosController.php
  Services/Listados/
    StockListadoService.php
    StockMinimosListadoService.php
    InformeIvaService.php
    ...
```

## Referencias en repo

- Patrón filtros + grid + Excel + PDF: `DiarioFacturacionView.vue`, `DiarioFacturacionService.php`
- ABC existente: `AbcVentasView.vue`, API ventas ABC
- Hub implementado: `ListadosHubView.vue`, `listados-nav.ts`
