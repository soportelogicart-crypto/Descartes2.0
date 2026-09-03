# Feature Specification: Albaranes periódicos (Mantenimiento)

**Feature Branch**: `008-albaranes-periodicos`  
**Created**: 2026-09-03  
**Status**: Draft  
**Input**: User description: "En Descartes 1.0 hay opción para crear un albarán periódico (presupuesto plantilla + Marcar Base Periodicidad + Gen.Alb). Implementarlo más fácil en Mantenimiento."

## Contexto

En **Descartes 1.0** la facturación recurrente (cuotas de mantenimiento, recibos domiciliados,
etc.) se configura de forma **oculta** dentro de Ventas:

| Paso legacy | Qué hace el usuario |
|-------------|---------------------|
| 1 | Crea un **presupuesto** (Tipo `P`) con las líneas fijas (ej. «Cuota Mantenimiento GARDEN») |
| 2 | Marca el documento como periódico (`Referencias = PERIODICO`, checkbox «Periódico») |
| 3 | **Op. Especiales → Marcar Base Periodicidad** — registra la plantilla en `AlbaranesPeriodicos` con periodicidad y fecha base |
| 4 | **Facturación → Generador manual → Gen.Alb** — genera albaranes Tipo `A` en un rango de fechas |

En **Descartes 2.0** hoy:

| Pieza 2.0 | Estado |
|-----------|--------|
| Tabla `AlbaranesPeriodicos` | Existe (legacy, sin cambios de esquema) |
| Motor **Gen.Alb** (`AlbaranesPeriodicosService::generar`) | ✅ Implementado |
| UI **Gen.Alb** en Generador manual de facturas | ✅ Botón + modal rango fechas |
| CRUD / listado de bases periódicas | ❌ No existe |
| «Marcar Base Periodicidad» / Op. Especiales en Ventas | ❌ No existe |
| Pantalla **Mantenimiento → Albaranes periódicos** | ❌ No existe |
| Copia de plantilla Tipo `P` (presupuesto) | ✅ Fix Fase 0 (`copiarAlbaran` usa `Tipo` plantilla) |

**Objetivo**: sustituir el flujo Op. Especiales por un **mantenimiento dedicado** que registre,
edite y consulte las bases periódicas; la **generación batch** sigue en Facturación (Gen.Alb).

**Fuera de alcance v1**:

- Crear la plantilla desde cero en Mantenimiento (el usuario la crea en **Ventas** como presupuesto/albarán)
- Facturación automática masiva distinta de Gen.Alb (ya cubierta)
- Domiciliación bancaria / remesas SEPA
- Informes nuevos de «PERIODICO» en listados (se mantiene `Referencia1` opcional por compatibilidad)
- TPV offline

---

## Decisiones cerradas (2026-09-03)

| # | Tema | Decisión |
|---|------|----------|
| 1 | Ubicación UX | **Mantenimiento → Albaranes periódicos** (`/mantenimiento/albaranes-periodicos`). Facturación conserva **Gen.Alb** para lotes mensuales. |
| 2 | Plantilla | Documento existente en `AlbaranesVentasCab` + líneas. Tipo **`P` (presupuesto) o `A` (albarán)** — se respeta el `Tipo` guardado en `AlbaranesPeriodicos`. |
| 3 | Periodicidad | Campo legacy `Periodicidad` = **días** (`smallint`). UI ofrece presets (7, 30, 60, 90, 365) + días personalizados. Regla Gen.Alb: si `% 30 === 0` → meses (`/30`); si no → días. |
| 4 | Fecha base | `UltimaGeneracion` = inicio del **último periodo generado** (legacy). Al **marcar** una base nueva, el usuario indica «desde qué fecha» (default: hoy). |
| 5 | Compatibilidad 1.0 | Al marcar, opcionalmente escribir `Referencia1 = 'PERIODICO'` en la plantilla si está vacía (no sobrescribir texto existente). |
| 6 | Permisos | Módulo `albaranes-periodicos` con ver / crear / editar / eliminar (Principio VI). Generar usa `facturacion-manual.crear` (existente) o acción «generar» con mismo permiso. |
| 7 | Esquema BD | **Sin migración nueva** — solo lectura/escritura de `AlbaranesPeriodicos` + JOINs a cabecera/cliente (Principio IV). |
| 8 | API | Rutas **dedicadas** bajo `/api/mantenimiento/albaranes-periodicos` (patrón Campañas / Ofertas, no `EntityConfig` genérico — PK compuesta). |
| 9 | Generación unitaria | Desde la fila del grid: «Generar ahora» llama al servicio existente con rango `{ hoy, hoy }` o rango calculado del periodo pendiente (ver FR-012). |

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Consultar bases periódicas de una tienda (Priority: P1)

Un usuario de administración abre **Mantenimiento → Albaranes periódicos**, filtra por tienda
(empresa) y ve todas las plantillas registradas: cliente, documento plantilla, periodicidad,
última generación y **próxima fecha estimada**.

**Why this priority**: Visibilidad que no existía en 2.0; sin listado no hay confianza operativa.

**Independent Test**: BD con filas en `AlbaranesPeriodicos`; abrir pantalla; aparecen con datos de cliente e importe de plantilla.

**Acceptance Scenarios**:

1. **Given** permiso `albaranes-periodicos.ver`, **When** abre la pantalla, **Then** ve grid paginado con columnas empresa, cliente, tipo/albarán plantilla, periodicidad legible, última generación, próxima (calculada).
2. **Given** filtro empresa `001`, **When** aplica, **Then** solo bases de esa tienda.
3. **Given** plantilla borrada en Ventas, **When** lista, **Then** la fila muestra aviso «Plantilla no encontrada» y permite quitar el registro.

---

### User Story 2 - Marcar un documento como base periódica (Priority: P1)

Tras crear un presupuesto en Ventas, el usuario va a Mantenimiento → Albaranes periódicos →
**Añadir**. Busca el documento (empresa + tipo + número o cliente + fecha), elige periodicidad
(mensual, trimestral, …) y fecha base. Al guardar se inserta en `AlbaranesPeriodicos`.

**Why this priority**: Sustituye «Marcar Base Periodicidad» — el corazón del feature.

**Independent Test**: Presupuesto `P` existente → marcar mensual → fila en grid → tabla SQL con `Periodicidad = 30`.

**Acceptance Scenarios**:

1. **Given** presupuesto `1/P/21000062` con líneas, **When** marca como periódico mensual desde 01/03/2026, **Then** INSERT en `AlbaranesPeriodicos` con PK correcta y `Periodicidad = 30`.
2. **Given** documento ya registrado, **When** intenta marcar de nuevo, **Then** error «Ya existe como base periódica» (409).
3. **Given** documento inexistente, **When** guarda, **Then** error 404 claro.
4. **Given** `Referencia1` vacía en plantilla, **When** marca con opción compatibilidad activa (default), **Then** `Referencia1 = 'PERIODICO'` en cabecera plantilla.
5. **Given** sin permiso crear, **When** intenta añadir, **Then** 403.

---

### User Story 3 - Editar y quitar una base periódica (Priority: P2)

El usuario puede cambiar periodicidad, resetear `UltimaGeneracion` (reiniciar contador) o
**eliminar** el registro (deja de generarse; la plantilla en Ventas no se borra).

**Why this priority**: Mantenimiento operativo sin tocar SQL.

**Independent Test**: Editar trimestral → 90 días; eliminar → desaparece del grid y de Gen.Alb.

**Acceptance Scenarios**:

1. **Given** base mensual, **When** cambia a trimestral, **Then** `Periodicidad = 90`.
2. **Given** base con última generación incorrecta, **When** edita fecha base, **Then** Gen.Alb recalcula desde la nueva fecha.
3. **Given** permiso eliminar, **When** confirma quitar, **Then** DELETE solo en `AlbaranesPeriodicos`.

---

### User Story 4 - Generar albarán desde mantenimiento (Priority: P2)

Desde una fila, **Generar ahora** crea un albarán `A` si el periodo corresponde (misma lógica
que Gen.Alb), muestra el número generado y enlace a Ventas.

**Why this priority**: Evita ir a Facturación para un solo cliente.

**Independent Test**: Base con periodo vencido → Generar ahora → nuevo albarán `A` + línea «Periodo …».

**Acceptance Scenarios**:

1. **Given** próxima fecha dentro de hoy, **When** genera, **Then** un albarán nuevo pendiente de facturar y `UltimaGeneracion` actualizada.
2. **Given** periodo no toca aún, **When** genera, **Then** mensaje «Nada pendiente en este periodo» sin error destructivo.
3. **Given** forma de pago con `CobroDeArqueo`, **When** Gen.Alb / generar, **Then** omite (legacy) con motivo en respuesta.

---

### User Story 5 - Gen.Alb batch en Facturación (Priority: P3 — ya implementado, verificar)

El usuario sigue usando **Facturación → Generador manual → Gen.Alb** con rango de fechas para
generar todos los periodicos de una empresa.

**Why this priority**: Paridad legacy; no reimplementar, solo **verificar** tras fix Tipo `P`.

**Independent Test**: 3 bases + rango mensual → N albaranes generados = esperado.

**Acceptance Scenarios**:

1. **Given** plantillas Tipo `P` y `A`, **When** Gen.Alb, **Then** ambas copian correctamente (fix FR-011).
2. **Given** rango sin periodos pendientes, **Then** `totales.omitidos` documentado en UI.

---

### Edge Cases

- Plantilla con líneas vacías → no permitir marcar (validación).
- Cliente dado de baja → permitir listar; aviso al generar.
- Cambio de precios en plantilla → el **próximo** albarán generado lleva precios actuales de la plantilla (legacy CopiaAlbaran).
- Dos usuarios generan a la vez → transacción + `UPDLOCK` en contador albarán (ya en servicio).
- Periodicidad `0` → rechazar al marcar/editar.

---

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Sistema MUST listar registros de `AlbaranesPeriodicos` con JOIN a `AlbaranesVentasCab` (cliente, importe, referencias) y `Clientes` (razón social).
- **FR-002**: Sistema MUST calcular **próxima generación** con la misma fórmula que `AlbaranesPeriodicosService` (días vs meses).
- **FR-003**: Usuario MUST poder **registrar** una base indicando `empresa`, `tipo`, `albaran`, `periodicidad`, `ultimaGeneracion`.
- **FR-004**: Sistema MUST validar que la plantilla existe y tiene al menos una línea de artículo antes de INSERT.
- **FR-005**: Sistema MUST impedir duplicar PK `(Empresa, Tipo, Albaran)`.
- **FR-006**: Usuario MUST poder **editar** `Periodicidad` y `UltimaGeneracion`.
- **FR-007**: Usuario MUST poder **eliminar** el registro de `AlbaranesPeriodicos` sin borrar la plantilla en Ventas.
- **FR-008**: UI MUST ofrecer buscador de documentos de venta (presupuesto/albarán) al añadir.
- **FR-009**: UI MUST mostrar enlace «Abrir plantilla» → ruta Ventas existente.
- **FR-010**: Sistema MUST aplicar permisos módulo `albaranes-periodicos` (ver/crear/editar/eliminar).
- **FR-011**: `AlbaranesPeriodicosService::copiarAlbaran` MUST leer cabecera y líneas usando el **`Tipo` de la plantilla** (`P` o `A`), no hardcode `'A'` en SELECT plantilla.
- **FR-012**: Acción «Generar ahora» MUST reutilizar `AlbaranesPeriodicosService::generar` filtrando por PK o rango `{ proximaFecha, proximaFecha }`.
- **FR-013**: Al marcar (opcional default ON), si `Referencia1` está vacía, MUST setear `PERIODICO` en plantilla.
- **FR-014**: Respuestas API MUST incluir `plantillaEncontrada: bool` en listado para filas huérfanas.

### Key Entities

- **Base periódica** (`AlbaranesPeriodicos`): vínculo empresa+tipo+albarán plantilla → periodicidad + última generación.
- **Plantilla**: filas en `AlbaranesVentasCab` / `AlbaranesVentasLin` (documento maestro no facturado recurrentemente).
- **Albarán generado**: nuevo `AlbaranesVentasCab` Tipo `A`, `Factura = 0`, copia de líneas + línea texto periodo.

---

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario marca un presupuesto como periódico en **≤ 2 minutos** sin Op. Especiales.
- **SC-002**: Gen.Alb genera albaranes desde plantillas Tipo `P` con **100% paridad** vs casos legacy conocidos (LOGIA cuota mantenimiento).
- **SC-003**: Listado muestra **próxima fecha** coherente con Gen.Alb para el 100% de filas con periodicidad > 0.
- **SC-004**: Operación «quitar base» no deja filas huérfanas sin aviso en UI.

---

## Assumptions

- La plantilla se sigue creando/editando en **Ventas** (presupuesto o albarán borrador).
- Una base periódica = un documento plantilla; no hay «versiones» de plantilla en v1.
- `Periodicidad` en días es suficiente (legacy); presets UI traducen a días.
- Gen.Alb existente en Facturación manual no se mueve de menú en v1.
- SQL Server 2008 R2 compatible (sin OFFSET en listados internos si aplica — usar paginación existente del proyecto).

---

## Referencias

- [research.md](./research.md) — análisis Descartes 1.0 y código 2.0
- [data-model.md](./data-model.md) — tablas y campos
- [plan.md](./plan.md) — fases de implementación
- [contracts/albaranes-periodicos-api.openapi.yaml](./contracts/albaranes-periodicos-api.openapi.yaml)
