# Quickstart: Albaranes periódicos

**Feature**: `008-albaranes-periodicos`

## Prerrequisitos

- BD con tabla `AlbaranesPeriodicos` (LOGIA u otra).
- Presupuesto o albarán plantilla en Ventas (Tipo `P` o `A`).
- Permisos:
  - `albaranes-periodicos` (migración `010-permisos-albaranes-periodicos.sql`)
  - `facturacion-manual.crear` para Gen.Alb y «Generar ahora»
- API sincronizada en XAMPP (`scripts/sync-xampp.ps1`).
- Puesto configurado con empresa activa.

## Flujo manual de prueba

### 1. Crear plantilla (Ventas)

1. Ventas → nuevo **Presupuesto** (`P`) o **Albarán** (`A`) para un cliente.
2. Añadir al menos una línea (ej. cuota mantenimiento).
3. Guardar. Anotar `Empresa / Tipo / Albarán`.

### 2. Marcar base (Mantenimiento)

1. Mantenimiento → Clientes → **Albaranes periódicos**.
2. **Añadir** → buscar el documento (cliente, tipo, nº).
3. Periodicidad **Mensual** (30 días), fecha base = hoy.
4. Guardar → fila visible con próxima fecha ≈ +1 mes.

### 3. Generar albarán

**Opción A — fila (Mantenimiento):**

- Seleccionar fila → **Generar ahora** (cuando la próxima fecha ≤ hoy).
- Banner verde con enlace **Abrir en Ventas →**.

**Opción B — batch (Facturación):**

- Facturación → Generador manual → **Gen.Alb**.
- Rango de fechas que incluya la próxima generación de las bases.
- Tras generar, panel **Albaranes periódicos generados** con enlace a cada albarán `A` nuevo y tipo de plantilla (`P`/`A`).

### 4. Verificar documento generado

- Nuevo albarán Tipo **`A`**, `Factura = 0`.
- Líneas copiadas de la plantilla (mismos importes actuales).
- Línea texto «Periodo dd/mm/yy al dd/mm/yy» (`Articulo = NO`).
- `UltimaGeneracion` actualizada en Mantenimiento (grid y SQL).

## Regresión Gen.Alb (Fase 4)

Comprobar **ambos** tipos de plantilla con el mismo flujo batch:

| Caso | Plantilla | Registro `AlbaranesPeriodicos.Tipo` | Resultado esperado |
|------|-----------|-------------------------------------|--------------------|
| R1 | Presupuesto `P` | `P` | Gen.Alb copia cab/lín del presupuesto; albarán nuevo `A` |
| R2 | Albarán `A` | `A` | Gen.Alb copia cab/lín del albarán plantilla; albarán nuevo `A` |

### Pasos R1 (Tipo P)

1. Crear presupuesto `P` con líneas → marcar en Mantenimiento (Tipo `P` en PK).
2. Ajustar `UltimaGeneracion` para que la próxima caiga en el rango Gen.Alb (o usar fecha base ayer + mensual).
3. Gen.Alb con rango `{ próxima, próxima }`.
4. UI: panel azul lista «plantilla **P**-xxxxx» → enlace al albarán `A` generado.
5. Ventas: abrir albarán generado → líneas coinciden con presupuesto (no vacío).

### Pasos R2 (Tipo A)

1. Repetir con albarán plantilla Tipo `A` (sin facturar).
2. Panel Gen.Alb debe mostrar «plantilla **A**-xxxxx».
3. Misma verificación de líneas e importe.

### Omisiones esperadas (no error)

- Periodo fuera del rango → `totales.generados = 0` o fila omitida.
- Forma de pago con `CobroDeArqueo` → omitido (legacy).
- Plantilla borrada → Mantenimiento muestra «No encontrada»; Gen.Alb la omite.

## SQL de comprobación

```sql
-- Bases registradas
SELECT Empresa, Tipo, Albaran, Periodicidad, UltimaGeneracion
FROM AlbaranesPeriodicos
WHERE Empresa = '1';

-- Últimos albaranes del cliente
SELECT TOP 10 Empresa, Tipo, Albaran, Cliente, Importe, Factura, Fecha
FROM AlbaranesVentasCab
WHERE Cliente = '<codigo>'
ORDER BY Fecha DESC;

-- Línea periodo en albarán generado
SELECT NroLin, Articulo, Descripcion, Importe
FROM AlbaranesVentasLin
WHERE Empresa = '1' AND Tipo = 'A' AND Albaran = <nuevo>;
```

## API (curl de referencia)

Tras login (`POST /api/auth/login` con sesión):

```http
GET /api/mantenimiento/albaranes-periodicos?empresa=1
POST /api/mantenimiento/albaranes-periodicos/{empresa}/{tipo}/{albaran}/generar
POST /api/facturacion/manual/periodicos/generar
  { "empresa": "1", "fechaDesde": "2026-03-01", "fechaHasta": "2026-03-31" }
```

## Notas

- **Gen.Alb** sigue en Facturación; Mantenimiento cubre alta/edición/listado y generación unitaria.
- Fix Fase 0: `copiarAlbaran` usa el `Tipo` de la plantilla en SELECT (no hardcode `'A'` en lectura).
- Para periodos atrasados, «Generar ahora» usa la fecha de próxima generación si ya venció.
