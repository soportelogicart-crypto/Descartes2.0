# Research: Albaranes periódicos

**Feature**: `008-albaranes-periodicos`  
**Date**: 2026-09-03

## Descartes 1.0 (VB6 + SQL)

Fuentes VB6 no están en el monorepo; el comportamiento se reconstruye desde:

- Capturas de usuario (presupuesto `1/21000062`, `Referencias = PERIODICO`, Op. Especiales)
- Tabla `AlbaranesPeriodicos` en `db/script.sql`
- Port PHP `AlbaranesPeriodicosService` (comentarios «legacy Gen.Alb → CopiaAlbaran»)

### Flujo operativo legacy

```
Ventas: crear Presupuesto (P) con líneas fijas
    → marcar convención PERIODICO (UI + Referencias)
    → Op. Especiales → Marcar Base Periodicidad
         INSERT/UPDATE AlbaranesPeriodicos
    → Facturación → Generador manual → Gen.Alb (rango fechas)
         CopiaAlbaran → nuevo Albarán (A), Factura=0
         Línea texto "Periodo dd/mm/yy al dd/mm/yy"
         UPDATE UltimaGeneracion
```

### Tabla `AlbaranesPeriodicos`

| Columna | Tipo | Notas |
|---------|------|--------|
| Empresa | nvarchar(3) | PK, tienda |
| Tipo | nvarchar(1) | PK, tipo documento **plantilla** (`P` o `A`) |
| Albaran | int | PK, número plantilla |
| UltimaGeneracion | datetime | Inicio último periodo facturado / generado |
| Periodicidad | smallint | **Días** (default 0). 30 ≈ mensual |

No hay columna `Periodico` en `AlbaranesVentasCab`; la marca es convención (`Referencia1`) + fila en `AlbaranesPeriodicos`.

### Reglas Gen.Alb (port 2.0)

1. Lee todas las filas `AlbaranesPeriodicos` de la empresa.
2. Calcula siguiente vencimiento desde `UltimaGeneracion` + `Periodicidad`:
   - Si `Periodicidad % 30 == 0` → sumar `Periodicidad/30` **meses**
   - Si no → sumar **días**
3. Si vencimiento ∈ `[fechaDesde, fechaHasta]` → copiar.
4. Omite si `Periodicidad <= 0`, sin `UltimaGeneracion`, plantilla no encontrada, o forma de pago con `FormasPago.CobroDeArqueo`.
5. Albarán generado siempre **Tipo `A`**, numeración `Empresas.UltAlbaranVen`.

### Bug conocido en 2.0 (pre-spec)

`copiarAlbaran()` SELECT plantilla con `Tipo = 'A'` fijo (líneas 187–188 de `AlbaranesPeriodicosService.php`), pero legacy guarda muchas plantillas como **Presupuesto `P`**. Debe usarse `$tipoPlantilla` del registro.

---

## Descartes 2.0 — inventario

| Componente | Ruta | Estado |
|------------|------|--------|
| Servicio generación | `descartes-api/.../AlbaranesPeriodicosService.php` | OK (fix Tipo pendiente) |
| Controller | `FacturacionController::periodicosGenerar` | OK |
| Ruta | `POST /api/facturacion/manual/periodicos/generar` | OK |
| UI Gen.Alb | `GeneracionFacturasManualView.vue` | OK |
| CRUD bases | — | **Falta** |
| Mantenimiento UI | — | **Falta** |
| Permisos | — | **Falta** (`albaranes-periodicos`) |
| Migración SQL | — | **No necesaria** |

### Patrón UI/API de referencia

- **Campañas**: PK compuesta, rutas dedicadas en `mantenimiento.php`, vista propia.
- **Ofertas clientes/proveedores**: buscador + grid + API no genérica.

---

## Presets periodicidad (UI → legacy días)

| Etiqueta UI | Días (`Periodicidad`) |
|-------------|----------------------|
| Semanal | 7 |
| Mensual | 30 |
| Bimestral | 60 |
| Trimestral | 90 |
| Anual | 365 |
| Personalizado | 1–999 (validar > 0) |

---

## Riesgos

| Riesgo | Mitigación |
|--------|------------|
| Plantilla `P` no copia | FR-011 fix Tipo en SELECT |
| Usuario no encuentra Gen.Alb | Mantener batch en Facturación + «Generar ahora» en grid |
| Datos legacy sin `UltimaGeneracion` | Validar al marcar; en listado mostrar «Revisar fecha base» |
| SQL 2008 paginación | Reutilizar `SqlPagination` / ROW_NUMBER del proyecto |
