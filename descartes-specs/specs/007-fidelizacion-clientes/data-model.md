# Data model: Fidelización de clientes

**Feature**: `007-fidelizacion-clientes`

## Tabla nueva `TiposCalculoFidelizacion`

Catálogo (mismo patrón que Impuestos / FormasPago: PK código + `Baja`).

| Columna | Tipo | Uso |
|---------|------|-----|
| `Codigo` | `nvarchar(20)` PK | Lo que elige la tienda (p. ej. `EUROS`, `PUNTOS`, `PUNTOS_DOBLES`) |
| `Nombre` | `nvarchar(50)` | Etiqueta en desplegable |
| `Motor` | `nvarchar(30)` | Estrategia en API: `NINGUNO`, `EUROS`, `PUNTOS` (v1). No es el código comercial. |
| `Factor` | `float` | Parámetro del motor (puntos por euro; default 1). Motores futuros pueden reutilizarlo. |
| `Configuracion` | `nvarchar(max)` NULL | JSON opcional para parámetros futuros sin otra migración |
| `Baja` | `bit` | Soft-delete; no sale en el desplegable |

Semilla de migración:

| Codigo | Nombre | Motor | Factor |
|--------|--------|-------|--------|
| `EUROS` | Saldo en euros (%) | `EUROS` | 1 |
| `PUNTOS` | Puntos (1 / €) | `PUNTOS` | 1 |

Un garden puede añadir `PTDOBL` / motor `PUNTOS` / Factor 2 **sin** desplegar código nuevo.

## `Empresas` (tienda)

| Columna | Uso v1 |
|---------|--------|
| `BloqueoFidelizacion` | Pausa el motor |
| `MinimoFidelizacion` | Umbral de base del documento |
| `TipoCalculoFidelizacion` | **Nueva** `nvarchar(20)` NULL/'' → ningún tipo. FK lógica a `TiposCalculoFidelizacion.Codigo` (sin FK SQL estricta, como el resto de maestros legacy) |

Migración: `db/migrations/009-tipos-calculo-fidelizacion.sql` (CREATE + ALTER + seed).

## `Clientes` / `Articulos`

Sin cambio de esquema. El **motor** de la fila elegida decide si se escribe `AcumuladoFidelizacion` o `AcumuladoPuntos`.

## Registro de motores (código PHP, no tabla)

Mapa `Motor` → estrategia. v1: `NINGUNO`, `EUROS`, `PUNTOS`. Un formato con matemática distinta = nueva clase + valor de `Motor`; `Empresas` no se altera.

## API (previsto)

- CRUD `/api/mantenimiento/tipos-calculo-fidelizacion` (entidad mantenimiento)
- GET/PUT tiendas: `tipoCalculoFidelizacion` (código)
- GET/PUT clientes: `fechaAltaFidelizacion`; UI según **motor** resuelto
- Cierre ventas: carga tipo de la tienda → despacha motor

## Invariantes

- Un documento cerrado mueve **como máximo** un acumulador (el que dicte el motor).
- Código vacío o motor `NINGUNO` ⇒ no cambia saldos al actualizar 2.0.
