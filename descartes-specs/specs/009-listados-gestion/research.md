# Research: Listados 1.0 → 2.0

**Spec**: [spec.md](./spec.md)  
**Fecha**: 2026-09-15

## Cómo funciona Descartes 1.0

### Entrada

- Menú `Men_Listados` en `DesAbacusIA\Menu.frm`: maestros (tiendas, impuestos, familias, clientes, artículos, proveedores…) y submenús de etiquetas/tarifas.
- **Muchos «listados» no están en ese menú**: ABC ventas/compras, stock, IVA, extracto, tickets, inventario, mermas, recibos se lanzan desde Ventas, Compras, Facturación e Inventario. Todos acaban en `frmListado`.

### Motor

| Pieza | Rol |
|-------|-----|
| `frmListado.frm` | UI genérica: combos + Desde/Hasta + Imprime / Configurar / Borrar |
| `Informes.mdb` | Catálogo Access (`Informes`: `Informe`, `Descripcion`, `Rpt`, `Programa='D'`) |
| `Listado.cls` | Crystal Reports (CRPEAuto): fórmulas, sort, export HTML/disco, email |
| `Rpts\*.rpt` | ~100+ diseños (más copias SQLServer / alfanuméricos; cientos de ficheros en árbol) |

`Form_Load` abre Jet sobre `Informes.mdb` y rellena el combo `DOC`. `ActivaListado` selecciona el informe. `Imprime_Click` arma selección Crystal (`RPT_*`).

### Qué duele (y no hay que copiar)

- Misma pantalla para todos: campos irrelevantes o 17 rangos.
- Sin vista previa de datos tabulares (solo visor Crystal).
- N entradas de menú por dimensión (ABC × ~15, Stock × 6).
- Dependencia de Access + Crystal (no hay en PHP/Vue; licencias y rutas `AppPath`).

### Qué sí hay que conservar

- Criterios de negocio (periodo, tienda, familia…artículo, IVA incluido/excluido).
- Agrupaciones (artículo vs familia vs cliente…).
- Totales (importe, coste, margen, unidades).
- Salida a impresora y a fichero (en 2.0: Excel + PDF/preview).

## Qué hay ya en 2.0

- Hub Listados: placeholder.
- `AbcVentasView` + `AbcVentasService`: informe real, filtros 1.0 casi literales (demasiados visibles).
- `DiarioFacturacionView`: patrón bueno (filtros + grid + columna + Excel + PDF).
- Mantenimientos: botón Listado = `window.print()`.
- Permiso `listados` en matriz, sin hijos.

## Recomendación técnica (para el plan)

- Catálogo TS en Gestión; API PHP por informe (SQL Server, tablas legacy).
- Shell Vue compartido (filtros, generar, grid, export, preview).
- No tabla SQL de catálogo v1.
- Compactar ABC al adoptar el shell (mismo endpoint).
- Límites de filas explícitos en API para no tumbar el navegador ni el PHP.

## Inventario 1.0 agrupado (para fases posteriores)

**Maestros (menú Listados):** tiendas, impuestos, formas de pago, actividades, macro/familias/sub, almacenes, proveedores, vendedores, clientes, artículos (datos, tarifas, etiquetas, escandallos).

**Ventas:** ABC (muchas dimensiones), diario ventas, tickets, IVA, extracto clientes, fidelización, pedidos, reservas, envíos.

**Compras:** ABC, precios artículo-proveedor, material pendiente/servido (operativo más que informe).

**Stock / inventario:** stock por dimensión, bajo mínimos, sin mínimos, mermas, movimientos, inventario.

**Facturación:** situación recibos; diario de facturación ya en 2.0.

v1 del spec cubre: hub + stock (+ mínimos) + tickets + IVA + extracto (si SQL claro) + enlace ABC/diario + (P2) listado de grids de mantenimiento.
