# Tasks: 009-listados-gestion

Convención: `[X]` hecho, `[ ]` pendiente.

## Fase 1 — Hub (US1)

- [X] T001 Catálogo `listados-nav.ts` (ids v1, categorías, keywords, rutas, módulos)
- [X] T002 Vista `ListadosHubView` (buscador NFD, tarjetas por categoría, Próximamente)
- [X] T003 Recientes (localStorage usuario + equipo, máx. 8)
- [X] T004 Ruta `/listados` con `meta.modulo: listados`
- [X] T005 Filtrar tarjetas por permiso del módulo de cada ítem
- [ ] T006 Prueba manual: sin `listados` → no entra; con permiso → ABC en ≤3 clics

## Fase 2 — Shell informe (US2 base)

- [ ] T010 Composable `useListadoInforme` (estado generar, filas, totales, cancelación) — parcial vía vistas
- [X] T011 Componente `ListadoInformeLayout.vue` (filtros, Más filtros, acciones)
- [X] T012 Util `csvExcel.ts` (BOM, `;`, coma decimal)
- [X] T013 Preview/imprimir listado HTML (`imprimirListadoHtml.ts`)
- [X] T014 Util atajos fecha (`useAtajosFecha.ts`) para informes con periodo

## Fase 3 — Stock (US2, FR-008)

- [X] T020 SQL agregado `[Stock]` + joins artículo/familia/proveedor
- [X] T021 Endpoint `GET /api/listados/stock` (límite 10 000 + `truncado`)
- [X] T022 Vista `/listados/stock` con `agruparPor`
- [X] T023 Ítem `stock` activo en catálogo

## Fase 4 — Otros informes v1

- [X] T030 Stock bajo mínimos (`Minimo` + stock calculado, API + vista)
- [X] T031 Informe IVA (ventas por tipo IVA, periodo + tienda)
- [X] T032 Informe tickets / diario ventas
- [ ] T033 Extracto clientes (o diferir según SQL)

## Fase 5 — ABC UX (US3)

- [X] T040 Reorganizar `AbcVentasView`: Más filtros plegado, rangos secundarios dentro

## Fase 6 — Mantenimientos (US4, P2)

- [ ] T050 Composable export grid filtrado
- [ ] T051 Sustituir `window.print()` en botones Listado de entidades
