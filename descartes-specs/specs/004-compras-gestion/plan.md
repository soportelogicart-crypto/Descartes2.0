# Implementation Plan: Módulo de Compras (Gestión)

**Branch**: `004-compras-gestion`  
**Date**: 2026-08-12  
**Spec**: [spec.md](./spec.md)

## Summary

MVP de Compras en Gestión 2.0 sobre tablas legacy (`AlbaranesCompraCab`/`Lin`, `PedidosCab`/`Lin`, `FacturasCompras`), UI tipo Ventas, API Slim, permisos Roles, impresión A4 vía plantillas de puesto.

## Technical approach

| Capa | Enfoque |
|------|---------|
| API | `descartes-api` rutas `/api/compras/...`, servicios + repositorios PDO |
| UI | `descartes-gestion` vistas bajo `/compras`, menú ya existente |
| Datos | Sin tablas nuevas salvo justificación; contadores en `Empresas` |
| Print | Reutilizar preview A4 + `printHtml` / impresoras Generales II |

## Phases (sugeridas)

1. **Foundation**: permisos módulo, rutas vacías, listado albaranes (US1)
2. **Albaranes CRUD**: alta/edición/bloqueo (US2) + stock si aplica
3. **Pedidos**: listado/alta (US3)
4. **Recepción**: pedido → albarán (US4)
5. **Facturas consulta + impresión** (US5–US6)

## Next artifacts

- [x] `research.md` — contadores, stock, bloqueos legacy
- [x] `data-model.md` — mapeo columnas ↔ API
- [x] `contracts/compras-api.openapi.yaml` — endpoints reales (T043)
- [x] `tasks.md` — checklist implementable (T001–T045); estudio IA fuera de listado
- [x] `quickstart.md` — permisos, contadores, stock (T044)
- [ ] *(más adelante)* `research-importacion-documento-proveedor.md` — estudio IA/OCR (fuera de v1; sin código en este plan)

## MVP primer demo

Phases 1–4 de `tasks.md`: shell + listado/detalle albaranes + alta/edición + actualizar stock.

## Constitution check

- Permisos desde Mantenimiento (VI)  
- Preferir schema legacy (IV)  
- No Veri*Factu en facturas proveedor v1 (consulta)
- Importación por IA: solo estudio; no implementación en este plan

## Sync XAMPP (dev local)

Si la API se prueba vía Apache en `C:\xampp\htdocs\descartes-api\`:

```powershell
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1
```

Detalle: [`descartes-api/SYNC-XAMPP.md`](../../../descartes-api/SYNC-XAMPP.md). Incluye rutas/servicios de **compras**.
