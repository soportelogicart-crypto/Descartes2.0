# Feature Specification: Fidelización de clientes

**Feature Branch**: `007-fidelizacion-clientes`  
**Created**: 2026-09-01  
**Status**: Draft  
**Input**: User description: "Tipo de cálculo; legacy hay dos formas; cada instalación elige el método de fidelización para sus clientes."

## Contexto

En Descartes 1.0 la fidelización vive en maestros (`Clientes`, `Empresas`, `Articulos`) y se
actualiza al cerrar ventas. En 2.0 **los campos ya están mapeados** en Mantenimiento; **no hay
motor**: al finalizar una venta no se toca ningún acumulador.

| Pieza 2.0 | Estado |
|-----------|--------|
| Cliente → tarjeta, `%`, acumulado €, acumulado puntos, tipo dto fidelización | Ficha (acumulados solo lectura) |
| Cliente → `FechaAltaFidelizacion`, `FidPregunta1`–`5` | Defaults de alta; **no en UI** |
| Tienda → `BloqueoFidelizacion`, `MinimoFidelizacion` | Ficha Facturación |
| Artículo → `BloqueoFidelizacion` | Ficha |
| Forma de pago → `TarjetaMonedero` | Maestro; cobro contra saldo (canje, no cálculo) |
| Cierre de venta (`VentaEscrituraService`) | **No actualiza fidelización** |
| TPV | No muestra ni acumula fidelización |

Tablas legacy (Principio IV):

| Concepto | Tabla / columnas |
|----------|------------------|
| Socio y saldos | `Clientes`: `PjeFidelizacion`, `AcumuladoFidelizacion`, `AcumuladoPuntos`, `TarjetaFidelizacion`, `TipoDescuentoFidelizacion`, `FechaAltaFidelizacion` |
| Política de tienda | `Empresas`: `BloqueoFidelizacion`, `MinimoFidelizacion` — **no hay tipo de cálculo** |
| Catálogo de métodos | **Nuevo** `TiposCalculoFidelizacion` (mismo patrón que Impuestos / FormasPago: código + baja) |
| Exclusión de artículo | `Articulos.BloqueoFidelizacion` |

**Fuera de alcance v1**:

- Canje (descontar saldo/puntos, vale, monedero, `TipoDescuentoFidelizacion` en cobro)
- Preguntas de alta (`FidPregunta1`–`5`) y campaña de marketing
- Sorteos (`Empresas.SorteosActivos`)
- Fidelización distinta por cajero (`Usuarios`)
- Motor offline TPV (irá con el sync de venta; el cálculo es del mismo servicio de cierre)

---

## Decisiones cerradas (2026-09-01)

| # | Tema | Decisión |
|---|------|----------|
| 1 | Dos métodos legacy | **Euros** (`AcumuladoFidelizacion` += base × `PjeFidelizacion` / 100) y **Puntos** (`AcumuladoPuntos` += puntos de la misma base). Una tienda usa **uno**, no los dos a la vez. |
| 2 | Quién elige | **La tienda** (`Empresas`), no el usuario de login ni cada socio. Cada instalación Descartes tiene su SQL (Principio III); el parámetro vive ahí. |
| 3 | Tipo de cálculo | **No** un enum cerrado `N`/`E`/`P` en `Empresas`. Maestro `TiposCalculoFidelizacion` (código de hasta 20 caracteres) y la tienda guarda solo el código en `Empresas.TipoCalculoFidelizacion`. Cada fila tiene un **motor** (estrategia registrada en API), factor y configuración JSON opcional; un formato nuevo = alta en el maestro si el motor ya existe, o motor nuevo en código + fila si cambia la matemática. Vacío / motor `NINGUNO` = no acumular. `BloqueoFidelizacion` sigue siendo **pausa** sin cambiar el tipo. Justificación Principio IV: tabla y columna nuevas (el 1.0 no tiene este catálogo); no se tocan acumuladores de `Clientes`. |
| 4 | Motores v1 | `NINGUNO` (no-op), `EUROS` (`AcumuladoFidelizacion` += base × `PjeFidelizacion` / 100), `PUNTOS` (`AcumuladoPuntos` += `floor(base × Factor)`; Factor por defecto 1). Semilla: códigos `EUROS` y `PUNTOS`. Un tipo futuro (sellos, puntos por artículo, escalado) reutiliza un motor o registra otro sin ALTER de `Empresas`. |
| 5 | Base de cálculo | Importe de líneas **no** bloqueadas, **no** abono (signo: el abono **resta** del acumulador). Umbral `MinimoFidelizacion`: si el documento (base elegible) no llega al mínimo, **no se acumula nada**. Cliente rápido `ZZZZZZZZZ` y clientes sin tarjeta **y** con `%` 0: no acumulan. |
| 6 | Dónde calcular | Un solo sitio: al **finalizar** venta (Gestión y TPV), mismo servicio que ya cierra documento, stock y arqueo. No un módulo paralelo. |
| 7 | Orden de entrega | **Primero** parámetro + UI Tiendas/Cliente (sin cobro). **Después** acumulación al finalizar. Canje en spec posterior. |

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Elegir método de fidelización en la tienda (Priority: P1)

Un responsable en Mantenimiento → Tiendas abre Facturación y elige un **tipo de cálculo** del
catálogo (desplegable de códigos activos). Puede dejarlo vacío, bloquear temporalmente sin
perder el tipo, y fijar el importe mínimo del ticket para empezar a acumular. Los tipos se
mantienen como el resto de maestros (alta de un código nuevo con motor ya registrado, p. ej.
«PUNTOS2» con motor `PUNTOS` y Factor 2).

**Why this priority**: Sin esta elección no se puede implementar el motor sin adivinar el método.

**Independent Test**: Guardar tienda con tipo `EUROS` y mínimo 10; recargar ficha; valores persistidos. Alta de tipo `PTDOBL` motor `PUNTOS` Factor 2; aparece en el desplegable.

**Acceptance Scenarios**:

1. **Given** permiso de tiendas, **When** edita «Tipo cálculo fidelización», **Then** las opciones son vacío + los códigos activos del maestro (semilla `EUROS`, `PUNTOS`).
2. **Given** tipo `EUROS`, **When** marca Bloqueo fidelización, **Then** el código se conserva y el motor (US3) no acumulará.
3. **Given** instalación nueva, **When** no ha tocado el campo, **Then** el tipo está vacío y no acumula.
4. **Given** un motor registrado, **When** da de alta otro código con ese motor y otro Factor, **Then** las tiendas pueden elegirlo sin cambiar el esquema.

---

### User Story 2 - Ficha de socio coherente con el método (Priority: P1)

En Mantenimiento → Clientes, la pestaña Fidelización muestra tarjeta, %, fecha de alta y **el
acumulador que corresponde** al método de la tienda del cliente (o de la tienda activa del
equipo si el cliente no tiene tienda). El otro acumulador no se ofrece como dato operativo
(puede quedar oculto o en solo lectura secundaria).

**Why this priority**: Evita que el garden rellene % y puntos a la vez sin saber cuál rige.

**Independent Test**: Tienda en modo Puntos; ficha cliente muestra acum. puntos y no invita a usar el saldo €.

**Acceptance Scenarios**:

1. **Given** tienda en Euros, **When** abre un cliente, **Then** ve `%` editable, acum. € solo lectura, y acum. puntos no es el dato principal.
2. **Given** tienda en Puntos, **When** abre un cliente, **Then** ve acum. puntos; el `%` no se usa en v1 (oculto o deshabilitado con texto de ayuda).
3. **Given** alta de socio con tarjeta, **When** guarda, **Then** `FechaAltaFidelizacion` queda a la fecha del día si estaba en el sentinel 1995-01-01.

---

### User Story 3 - Acumular al finalizar la venta (Priority: P1)

Al cerrar Ticket / Albarán / Factura (no presupuesto), el sistema actualiza **un** acumulador
del cliente según el **motor** del tipo de cálculo de la **tienda de la venta**
(`AlbaranesVentasCab.Empresa` → `TiposCalculoFidelizacion`).

**Why this priority**: Es el valor de negocio; US1–US2 solos no fidelizan.

**Independent Test**: Cliente 5 %, tienda Euros, venta 100 € de líneas no bloqueadas → acum. € +5; misma venta en modo Puntos → puntos +100.

**Acceptance Scenarios**:

1. **Given** método Euros, cliente con `%` 5 y tarjeta, **When** finaliza venta de base 100, **Then** `AcumuladoFidelizacion` aumenta 5.
2. **Given** método Puntos, **When** finaliza la misma venta, **Then** `AcumuladoPuntos` aumenta 100 y el saldo € no cambia.
3. **Given** línea con artículo bloqueado, **When** finaliza, **Then** esa línea no entra en la base.
4. **Given** documento por debajo de `MinimoFidelizacion`, **When** finaliza, **Then** no hay movimiento.
5. **Given** abono de una venta que sí acumuló, **When** finaliza el abono, **Then** se resta la misma regla (euros o puntos) sobre las líneas abonadas.
6. **Given** método Ninguno o tienda bloqueada, **When** finaliza, **Then** los acumuladores no cambian.
7. **Given** cliente `ZZZZZZZZZ`, **When** cobra en TPV, **Then** no acumula.

---

### User Story 4 - Visibilidad en TPV (Priority: P2)

Con ticket abierto y cliente asignado (no venta rápida), la cabecera del TPV muestra el saldo
del método activo (euros o puntos) de solo lectura. No se canjea en esta historia.

**Why this priority**: El cajero debe poder decirle al socio cuánto lleva; el canje es otro spec.

**Independent Test**: Asignar cliente con 12,50 € acumulados en tienda modo Euros → se ve en cabecera.

**Acceptance Scenarios**:

1. **Given** método Euros y cliente con acum. > 0, **When** se asigna en TPV, **Then** se muestra el saldo en €.
2. **Given** método Ninguno, **When** hay cliente, **Then** no se muestra bloque de fidelización.

---

### Edge Cases

- Presupuesto: no acumula (el documento no es venta cerrada).
- Factura a partir de albaranes ya acumulados: **no volver a acumular** (solo el cierre que genera el movimiento de stock/caja acordado; si el albarán ya acumuló, la factura no duplica).
- Recalcular venta abierta (PUT líneas): no mueve acumuladores hasta finalizar.
- Anular venta ya finalizada: revertir el movimiento de fidelización de ese documento (misma cantidad que se sumó).
- `%` negativo o > 100: rechazar en ficha cliente; el motor trata `%` ≤ 0 como no socio en modo Euros.
- Multi-tienda: rige la tienda **de la venta**, no la del equipo si difieren.
- Tipo de cálculo de baja o motor desconocido: no acumular; aviso en cierre, venta sí se cierra.
- Sin red (TPV): la fase 1 solo configura; el futuro sync offline aplicará el mismo motor al consolidar la venta.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST persistir `Empresas.TipoCalculoFidelizacion` como código del maestro (`nvarchar(20)`), vacío = no acumular.
- **FR-002**: Mantenimiento MUST exponer el catálogo `TiposCalculoFidelizacion` (código, nombre, motor, factor) y Tiendas MUST elegir un código activo (permiso `tiendas`).
- **FR-003**: El sistema MUST NOT acumular si el código está vacío, el tipo está de baja, el motor es `NINGUNO` o `BloqueoFidelizacion` está activo. Un motor no registrado MUST no acumular y MUST dejar aviso (no silenciar el cierre de venta).
- **FR-004**: Motor `EUROS` MUST sumar `round(base × PjeFidelizacion / 100, 2)` a `AcumuladoFidelizacion`.
- **FR-005**: Motor `PUNTOS` MUST sumar `floor(base × Factor)` a `AcumuladoPuntos` (Factor del tipo; semilla 1).
- **FR-006**: La base MUST excluir líneas de artículos con `BloqueoFidelizacion` y MUST usar el importe de línea ya con descuento comercial.
- **FR-007**: El cálculo MUST ejecutarse una sola vez por documento, en el cierre (finalizar / abono / anulación de documento cerrado), reutilizado por Gestión y TPV. El cierre MUST resolver tienda → código → fila del maestro → motor, no ramificar por literales `N`/`E`/`P`.
- **FR-008**: Cliente de venta rápida y cliente con `%` 0 en motor `EUROS` MUST NOT acumular. Motor `PUNTOS` v1 MUST exigir tarjeta de fidelización no vacía.
- **FR-009**: El sistema MUST NOT canjear saldo ni puntos en este spec.
- **FR-010**: Un módulo de permisos nuevo NO es necesario; se reutilizan `tiendas`, `clientes`, `ventas` / `tpv`.
- **FR-011**: La tabla nueva y el ALTER de `Empresas` MUST documentarse en migración; default del FK vacío para no romper lecturas legacy ni acumular al actualizar.
- **FR-012**: Añadir un formato futuro MUST poder hacerse con una fila nueva si el motor ya existe; un motor nuevo MUST registrarse en API (mapa código de motor → estrategia) sin cambiar `Empresas`.

### Key Entities

- **Tipo de cálculo**: código comercial, nombre, motor (estrategia), factor, baja.
- **Política de tienda**: código de tipo, bloqueo, mínimo.
- **Registro de motores (código, no tabla)**: `NINGUNO`, `EUROS`, `PUNTOS` en v1; ampliables.
- **Socio**: tarjeta, %, fecha alta, dos acumuladores (el motor decide cuál mueve).
- **Movimiento implícito**: no hay tabla nueva de histórico en v1; el saldo está en `Clientes`. Un log dedicado es fase 2 si hace falta auditoría.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un administrador elige un tipo del catálogo en la tienda y lo vuelve a ver tras recargar, en menos de 1 minuto.
- **SC-002**: Una venta de prueba con motor `EUROS` deja el acumulado € coherente al céntimo con el % del socio.
- **SC-003**: La misma venta con motor `PUNTOS` no altera el acumulado €.
- **SC-004**: Cobrar sin socio (TPV rápido) deja ambos acumuladores iguales que antes.
- **SC-005**: Se puede dar de alta un segundo código con motor `PUNTOS` y Factor distinto y asignarlo a una tienda sin migración de `Empresas`.

## Assumptions

- `TipoDescuentoFidelizacion` se reserva para el spec de **canje**.
- No existe este catálogo en el dump `sql/Empresas.sql`; tabla + FK es el mecanismo 2.0.
- «Cada usuario» en el enunciado original = cada **instalación / tienda**, no cada cajero.
- Formatos futuros (sellos, puntos por artículo, tramos) son **motores nuevos** o filas con otro Factor; no se enumeran en `Empresas`.
