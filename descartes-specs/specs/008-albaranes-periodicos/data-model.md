# Data Model: Albaranes periódicos

**Feature**: `008-albaranes-periodicos`

## Tablas (sin cambios de esquema v1)

### `AlbaranesPeriodicos` (registro base periódica)

PK: `(Empresa, Tipo, Albaran)`

| Columna SQL | API (camelCase) | Descripción |
|-------------|-----------------|-------------|
| Empresa | empresa | Código tienda (3) |
| Tipo | tipo | Tipo documento plantilla: `P`, `A`, … |
| Albaran | albaran | Número documento plantilla |
| UltimaGeneracion | ultimaGeneracion | datetime ISO; inicio último periodo generado |
| Periodicidad | periodicidad | Días entre periodos (smallint) |

### Plantilla (solo lectura para validación / JOIN)

**`AlbaranesVentasCab`** — campos usados en UI/API:

| Columna | Uso |
|---------|-----|
| Cliente | Filtro y columna grid |
| RazonSocial | Columna grid |
| Referencia1, Referencia2 | Compat legacy PERIODICO |
| Importe | Columna grid |
| Fecha | Contexto plantilla |
| FormaPago | Filtro `CobroDeArqueo` al generar |

**`AlbaranesVentasLin`** — validar ≥ 1 línea al marcar.

**`Clientes`** — razón social, NIF (display).

**`FormasPago`** — `CobroDeArqueo` (omitir generación).

### Documento generado (salida Gen.Alb)

Nueva fila **`AlbaranesVentasCab`**:

- `Tipo = 'A'`
- `Factura = 0`, `FacturaTipo` null
- `Estado` null (cerrado y pendiente de facturar; no hereda `B` de un presupuesto)
- `Fecha` = fecha generación
- Copia resto campos de plantilla (excepto skip list en servicio)
- Línea texto en `AlbaranesVentasLin`: `Articulo = 'NO'`, descripción periodo

## DTO listado (API)

```typescript
interface AlbaranPeriodicoListItem {
  empresa: string
  tipo: string
  albaran: number
  periodicidad: number
  periodicidadLabel: string   // "Mensual", "90 días", …
  ultimaGeneracion: string | null
  proximaGeneracion: string | null
  plantillaEncontrada: boolean
  cliente: string
  razonSocial: string
  importePlantilla: number
  referencia1: string | null
}
```

## DTO alta/edición

```typescript
interface AlbaranPeriodicoWrite {
  empresa: string
  tipo: string
  albaran: number
  periodicidad: number
  ultimaGeneracion: string    // YYYY-MM-DD o ISO datetime
  marcarReferenciaPeriodico?: boolean  // default true
}
```

## Permisos (`RolPermisos.Modulo`)

| Modulo | Acciones |
|--------|----------|
| `albaranes-periodicos` | ver, crear, editar, eliminar |

Migración permisos: nuevo script `010-permisos-albaranes-periodicos.sql` (INSERT ADMIN + seed).

## Índices

No se añaden índices v1; volumen bajo (decenas de filas por empresa). Listado filtra por `Empresa`.
