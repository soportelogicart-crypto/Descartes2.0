# Implementation Plan: Venta táctil (TPV)

**Branch**: `006-tpv-venta-tactil`  
**Date**: 2026-08-31  
**Spec**: [spec.md](./spec.md)

## Summary

MVP del **TPV táctil** para Garden/retail: pantalla fullscreen en Electron (`/tpv`), rejilla legacy
(`DefPlus`), ticket en curso, cobro como **Ticket**, impresión térmica, sesión de caja y
**offline-first** (SQLite en Electron + sync idempotente). Reutiliza `VentaEscrituraService`,
`ArqueoService` y el shell `descartes-electron`.

## Technical approach

| Capa | Enfoque |
|------|---------|
| UI | `descartes-gestion` → `/tpv` (`TpvLayout` fullscreen, `TpvVentaView`) |
| Shell | `descartes-electron` → `appUrl` … `/tpv`; SQLite + worker sync |
| API | `descartes-api` → `/api/tpv/...` (contexto, teclado, sync, reserva números) + reuse `/api/ventas` |
| Datos | Legacy sin ALTER obligatorio; opcional `TpvSyncLog`; SQLite solo en Electron |
| Print | `window.descartes.printTicket` + plantillas scope tickets |

## Architecture

```
┌──────────────────┐     online      ┌─────────────────┐     ┌──────────────┐
│ TpvVentaView     │ ───────────────►│ descartes-api   │────►│ SQL Server   │
│ (/tpv)           │                 │ /api/tpv/*      │     │ ventas+sess. │
└────────┬─────────┘                 │ /api/ventas/*   │     └──────────────┘
         │                           └─────────────────┘
         │ window.descartes
         ▼
┌──────────────────┐
│ descartes-electron│ SQLite: draft, sync_queue, cache
│ sync worker       │ POST /api/tpv/sync/ventas
└──────────────────┘
```

## Phases (implementación)

| Fase | Alcance | User stories |
|------|---------|--------------|
| **0 Foundation** | Permisos `tpv`, rutas, `TpvLayout`, ping API, contexto puesto/sesión | US1 |
| **1 Teclado + ticket** | `GET teclado/nivel`, rejilla táctil, borrador, añadir/quitar líneas | US2 |
| **2 Cobro online** | Finalizar ticket, `VentaEscrituraService`, impresión | US3, US7 |
| **3 Electron caja** | Fullscreen, config `/tpv`, ticket RAW | US3 |
| **4 Offline** | SQLite, cola, reserva números, sync idempotente | US4 |
| **5 Extras** | Escáner, cliente, descuento, Tcl_* reducidos | US5, US6 |

**Primer demo (Fases 0–2 + 3 mínimo)**: entrar en TPV → pulsar artículo → cobrar → ticket → venta en Gestión.

## Constitution check

| Principio | Estado |
|-----------|--------|
| I. Programas independientes | ✅ TPV vía API; Gestión no requerida en caja |
| II. TPV offline-first | ✅ Fase 4 obligatoria antes de producción; online demo en Fase 2 |
| III. Aislamiento por BD | ✅ Sin cambio multi-tenant |
| IV. Modelo heredado | ✅ DefPlus + AlbaranesVentas; `TpvSyncLog` opcional justificado |
| V. Fiscal | ✅ Solo Ticket MVP; Factura fase 2 |
| VI. Permisos | ✅ Módulo `tpv` en Roles |
| VII. Backend ligero | ✅ Servicios delgados + reuse ventas |
| VIII. Electron + periféricos | ✅ Shell existente |
| IX. Local first | ✅ XAMPP + SQL local |

## Key files (previstos)

```
descartes-api/src/
  Routes/tpv.php
  Controllers/TpvController.php
  Services/Tpv/TpvContextoService.php
  Services/Tpv/TpvTecladoService.php
  Services/Tpv/TpvSyncService.php

descartes-gestion/src/
  views/tpv/TpvVentaView.vue
  layouts/TpvLayout.vue
  components/tpv/TpvTecladoGrid.vue
  components/tpv/TpvTicketPanel.vue
  api/tpv.ts
  stores/tpvVenta.ts

descartes-electron/electron/
  tpv-db.js          -- SQLite
  tpv-sync.js        -- worker

db/migrations/007-permisos-tpv.sql
```

## Next artifacts

- [x] `research.md` — teclado, offline, permisos
- [x] `data-model.md` — DefPlus, borrador, sync
- [x] `contracts/tpv-api.openapi.yaml`
- [x] `quickstart.md`
- [x] `tasks.md` — checklist T001–T051
- [ ] *(fase 2)* `research-tpv-restaurante-peso.md`

## Sync XAMPP (dev)

Tras cambios PHP:

```powershell
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1
```

## MVP demo checklist

1. Login usuario con permiso `tpv`.
2. Configurar puesto en Electron (`equipo.json`) con tienda y puesto `01`.
3. Abrir `npm run dev` en electron → `/tpv`.
4. Pulsar artículo en teclado `001`.
5. Cobrar → ticket impreso → venta visible en Gestión → Ventas.
