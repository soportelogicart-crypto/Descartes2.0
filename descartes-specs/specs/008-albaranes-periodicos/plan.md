# Plan: Albaranes periódicos

**Feature**: `008-albaranes-periodicos`  
**Branch**: `jordi` (desarrollo) → PR a `main`

## Fase 0 — Fix generación (prerrequisito)

- [x] **T0.1** `AlbaranesPeriodicosService::copiarAlbaran` — SELECT cabecera/líneas con `Tipo` plantilla (`$tipoPlantilla`), no `'A'` fijo.
- [x] **T0.2** Smoke LOGIA: 100+ bases Tipo `A` resuelven cab/lín; fix `P` listo para presupuestos plantilla (FR-011).

## Fase 1 — API Mantenimiento (P1)

- [x] **T1.1** `AlbaranesPeriodicosConsultaService` — listar con JOINs, calcular `proximaGeneracion`, paginación SQL 2008.
- [x] **T1.2** `AlbaranesPeriodicosEscrituraService` — create / update / delete + validaciones FR-004, FR-005.
- [x] **T1.3** Rutas en `mantenimiento.php` + controller methods (o controller dedicado).
- [x] **T1.4** Migración `010-permisos-albaranes-periodicos.sql` + seed ADMIN.
- [x] **T1.5** OpenAPI alineado con [contracts](./contracts/albaranes-periodicos-api.openapi.yaml).

## Fase 2 — UI Mantenimiento (P1)

- [x] **T2.1** `AlbaranesPeriodicosView.vue` — grid (patrón Campañas / Ofertas).
- [x] **T2.2** Modal «Añadir» — buscador ventas (empresa, cliente, tipo, nº).
- [x] **T2.3** Modal editar periodicidad + fecha base.
- [x] **T2.4** Router `/mantenimiento/albaranes-periodicos` + entrada menú (Facturación o Clientes).
- [x] **T2.5** `entidades.ts` / permisos frontend.

## Fase 3 — Generar desde grid (P2)

- [x] **T3.1** Endpoint `POST .../generar` (una PK) o reutilizar facturación con body `{ empresa, fechaDesde, fechaHasta, solo: [pk] }`.
- [x] **T3.2** Botón fila «Generar ahora» + toast con nº albarán + link Ventas.

## Fase 4 — Verificación Facturación (P3)

- [x] **T4.1** Regresión Gen.Alb en `GeneracionFacturasManualView.vue` con plantillas P y A.
- [x] **T4.2** Documentar en [quickstart.md](./quickstart.md).

## Fuera de esta entrega

- Op. Especiales en Ventas (redundante si Mantenimiento cubre US2).
- Informes listados «PERIODICO».

## Dependencias

- Ventas: consulta documentos para buscador (API existente `GET /api/ventas/...`).
- Facturación: `AlbaranesPeriodicosService` (ya registrado en DI).

## Estimación orientativa

| Fase | Esfuerzo |
|------|----------|
| 0 | 0.5 d |
| 1 | 1.5 d |
| 2 | 1.5 d |
| 3 | 0.5 d |
| 4 | 0.5 d |
