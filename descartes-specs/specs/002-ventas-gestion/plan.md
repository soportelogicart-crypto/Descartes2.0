# Implementation Plan: Módulo de Ventas (Gestión)

**Branch**: `002-ventas-gestion` | **Date**: 2026-07-22 | **Spec**: [spec.md](./spec.md)  
**Input**: Feature specification from `/specs/002-ventas-gestion/spec.md`

> **Aviso**: `/speckit.clarify` no se completó. FR-020 y FR-021 se cerraron por asunción del spec
> (unificar Registradora; diario solo consulta). Ver [research.md](./research.md). Riesgo de
> retrabajo si negocio contradice esas decisiones.

## Summary

Implementar en **Gestión** el módulo de **consulta y gestión administrativa** sobre datos de
venta ya producidos por el TPV: listado/detalle de ventas, arqueo y desglose por puesto,
diario de anulaciones, cobros/pagos, liquidación de vales y pedidos de cliente (alta +
marcado Impreso). Sin crear ventas ni editar importes cobrados.

**Enfoque técnico**: rutas dedicadas `/api/ventas/*` en `descartes-api` (PHP/Slim) sobre
tablas legado (`AlbaranesVentasCab/Lin`, `Arqueo`, `Sesiones`, `LogAnulaciones`, `Vales`,
`PedidosClientes`); SPA Vue 3 con submenús bajo `/ventas`; permisos módulo `ventas`;
prerrequisito ampliar maestro `formas-pago` con `CobroDeArqueo` / `CobroPago`.

## Technical Context

**Language/Version**: PHP **8.0+** (API), Vue 3.4+ / TypeScript 5+ (Gestión)  
**Primary Dependencies**: Slim 4, slim/psr7, php-di, sqlsrv/PDO; Vue 3, Vite 5, Vue Router, Pinia, axios  
**Storage**: SQL Server por empresa (`larasa` en dev); **sin migraciones nuevas** en MVP  
**Testing**: PHPUnit 9.x (API), Vitest + Vue Test Utils (Gestión)  
**Target Platform**: XAMPP (Apache + PHP 8.0.x) Windows; navegador Chromium/Edge  
**Project Type**: API + SPA (`descartes-api` + `descartes-gestion`); TPV fuera de alcance  
**Performance Goals**: Localizar venta del día en ≤3 acciones UI; listados página ≤500 filas útiles en LAN  
**Constraints**: Sin BD directa desde Vue; sin editar importes de ventas cerradas; permisos configurables; mensajes en español; respeto esquema legado  
**Scale/Scope**: 7 superficies UI (ventas, arqueo, desglose, anulaciones, cobros/pagos, vales, pedidos); 1 BD por cliente

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Evidencia / notas |
|-----------|--------|-------------------|
| I. Programas independientes | ✅ PASS | Solo Gestión ↔ API; TPV no se modifica; sin acceso SQL desde Vue |
| II. TPV offline-first | ✅ N/A | Ventas se originan en TPV; este feature solo consume datos ya persistidos |
| III. Aislamiento por empresa | ✅ PASS | Misma cadena `ClienteDbMiddleware` / DSN por cliente |
| IV. Modelo heredado | ✅ PASS | Sin tablas nuevas; mapeo documentado en `data-model.md` |
| V. Cumplimiento fiscal | ✅ PASS | No emite facturas; solo muestra vínculo `Factura*` existente |
| VI. Roles configurables | ✅ PASS | Módulo `ventas` ya en roles; acciones ver/crear/editar |
| VII. Backend ligero | ✅ PASS | Rutas/servicios explícitos Slim; sin ORM |
| VIII. Frontend simple | ✅ PASS | Reutilizar grids/patrones mantenimiento; subrutas `/ventas/*` |
| IX. Local primero | ✅ PASS | XAMPP + SQL Server local |

**Post-design re-check (Phase 1)**: Gates en PASS. Sin Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/002-ventas-gestion/
├── plan.md                 # Este archivo
├── research.md             # Decisiones R-001…R-012
├── data-model.md           # Mapeo legado
├── quickstart.md           # Arranque y smoke tests
├── contracts/
│   └── ventas-api.openapi.yaml
└── tasks.md                # (/speckit.tasks — pendiente)
```

### Source Code (repository root)

```text
c:\descartes-2.0\
├── db\
│   └── script.sql                    # AlbaranesVentas*, Arqueo, Sesiones, LogAnulaciones, Vales, PedidosClientes, FormasPago
│
├── descartes-api\
│   ├── public\index.php
│   └── src\
│       ├── Routes\
│       │   ├── mantenimiento.php     # Extender formas-pago fields
│       │   └── ventas.php            # NUEVO
│       ├── Http\Controllers\
│       │   └── Ventas\…              # NUEVO (o VentasController + actions)
│       ├── Services\
│       │   └── Ventas\…              # NUEVO
│       └── Config\entities.php       # formas-pago: cobroDeArqueo, cobroPago
│
└── descartes-gestion\
    ├── src\
    │   ├── config\
    │   │   ├── menu-principal.ts     # Ya tiene ventas
    │   │   └── ventas-nav.ts         # NUEVO submenús
    │   ├── router\index.ts           # Quitar placeholder; rutas hijas
    │   ├── views\ventas\             # NUEVO
    │   │   ├── VentasListView.vue
    │   │   ├── ArqueoView.vue
    │   │   ├── ArqueoDesgloseView.vue
    │   │   ├── AnulacionesView.vue
    │   │   ├── CobrosPagosView.vue
    │   │   ├── ValesView.vue
    │   │   └── PedidosClientesView.vue
    │   └── api\ventas.ts             # NUEVO cliente HTTP
    └── …
```

**Structure Decision**: Feature transversal API+Gestión sobre esquema legado. No se toca `venta/`
(Electron). Contratos en OpenAPI; implementación Slim + Vue alineada a 001.

## Complexity Tracking

> No aplica — sin violaciones constitucionales.

## Phase 0 — Research

Completado en [research.md](./research.md). Decisiones clave:

1. Registradora = filtro puesto en desglose.
2. Anulaciones = solo lectura.
3. API dedicada `/api/ventas/*`.
4. Sin migraciones SQL.
5. Extender `formas-pago` con flags de arqueo/cobro-pago.

## Phase 1 — Design & Contracts

| Artefacto | Estado |
|-----------|--------|
| [data-model.md](./data-model.md) | Hecho |
| [contracts/ventas-api.openapi.yaml](./contracts/ventas-api.openapi.yaml) | Hecho |
| [quickstart.md](./quickstart.md) | Hecho |
| Agent context | Actualizar vía script |

### Orden de implementación sugerido (para `/speckit.tasks`)

1. Extender `formas-pago` (API + UI mantenimiento).
2. API consulta: ventas list/detail → arqueo/desglose → anulaciones → cobros-pagos.
3. API escritura: vales (emitir/liquidar) → pedidos (alta/impreso).
4. Frontend: shell menú `/ventas` + pantallas en el mismo orden.
5. Tests: cuadre arqueo; bloqueo vale caducado/liquidado; permisos ver/editar.
6. Sync a XAMPP `htdocs` si aplica.

## Phase 2 — No en este comando

`tasks.md` se genera con `/speckit.tasks`.
