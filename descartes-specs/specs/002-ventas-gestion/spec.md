# Feature Specification: Módulo de Ventas (Gestión)

**Feature Branch**: `002-ventas-gestion`  
**Created**: 2026-07-16  
**Status**: Draft  
**Input**: User description: "Construir el módulo de Ventas del programa de Gestión de Descartes 2.0. Este módulo es de CONSULTA y gestión administrativa sobre las ventas ya realizadas en el TPV…"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Consultar ventas realizadas (Priority: P1)

Un responsable de tienda o administración localiza ventas ya realizadas en el TPV: consulta el
listado, aplica filtros (fecha, tienda/puesto, vendedor, cliente, estado) y abre el detalle de
una venta (cabecera con importes por tipo de IVA, formas de pago, estado, si está facturada y
número de factura; líneas con artículo, cantidad, precio y descuentos). No crea ni modifica
importes de ventas cerradas desde Gestión.

**Why this priority**: Es el núcleo del módulo y el criterio de éxito principal (localizar
cualquier venta del día en pocas acciones). Sin esta consulta el resto de submenús pierde
contexto operativo.

**Independent Test**: Con ventas del día ya sincronizadas desde TPV, un usuario con permiso de
consulta localiza una venta concreta por cliente, puesto o franja horaria en como máximo tres
acciones de interfaz y ve cabecera y líneas coherentes con lo cobrado en TPV.

**Acceptance Scenarios**:

1. **Given** un usuario autenticado con permiso de ver sobre Ventas y ventas del día actuales
   en el sistema, **When** filtra por fecha de hoy y por cliente (o puesto, o rango horario),
   **Then** el listado muestra solo las ventas que cumplen el filtro y puede abrir el detalle
   en como máximo tres acciones desde la entrada al submenú.
2. **Given** una venta cerrada con varias líneas, varios tipos de IVA y varias formas de pago,
   **When** el usuario abre su detalle, **Then** ve cliente, fecha, vendedor, puesto, importes
   por IVA, formas de pago usadas, estado, indicador de facturada y número de factura si
   existe, más las líneas con artículo, cantidad, precio y descuentos.
3. **Given** una venta cerrada, **When** el usuario intenta alterar importes cobrados o líneas
   desde Gestión, **Then** el sistema no permite la edición de esos importes (solo consulta).

---

### User Story 2 - Cuadrar el arqueo de caja (Priority: P1)

Un cajero o responsable consulta el arqueo de una sesión de caja y puesto: efectivo entrado,
acumulados por formas de pago que cuentan para arqueo (según configuración de cada forma de
pago) y desglose por denominación de moneda/billete (hasta 20 tipos). El total del arqueo debe
cuadrar con la suma de esas formas de pago de la sesión.

**Why this priority**: El cuadre de caja es operación diaria crítica y segundo criterio de
éxito explícito del módulo.

**Independent Test**: Para una sesión y puesto con movimientos conocidos, el total de arqueo
mostrado coincide exactamente con la suma de movimientos cuya forma de pago está marcada como
«cuenta para arqueo».

**Acceptance Scenarios**:

1. **Given** una sesión de caja cerrada o en curso con movimientos en un puesto, **When** el
   usuario consulta el arqueo de esa sesión y puesto, **Then** ve efectivo entrado, totales por
   forma de pago que cuenta para arqueo y desglose por denominación (hasta 20 tipos).
2. **Given** formas de pago configuradas unas con «cuenta para arqueo» y otras sin ella,
   **When** se calcula el arqueo de la sesión, **Then** solo intervienen las marcadas para
   arqueo y el total cuadra con esa suma.
3. **Given** un usuario sin permiso de ver Ventas, **When** intenta acceder a Arqueo de caja,
   **Then** el acceso se deniega de forma visible.

---

### User Story 3 - Desglose detallado del arqueo (Priority: P2)

Un responsable revisa el desglose detallado del arqueo por denominación de moneda/billete
dentro de una sesión y puesto concretos, para contrastar el conteo físico con lo registrado.

**Why this priority**: Amplía el arqueo agregado con el detalle operativo necesario para
resolver descuadres; depende de la historia de arqueo pero aporta valor por sí sola.

**Independent Test**: Seleccionando una sesión y puesto, el usuario obtiene el desglose por
cada denominación registrada y puede reconciliarlo con el total de arqueo de esa sesión/puesto.

**Acceptance Scenarios**:

1. **Given** un arqueo con desglose por denominaciones en una sesión y puesto, **When** el
   usuario abre Desglose de arqueo para esa sesión y puesto, **Then** ve el detalle por cada
   tipo de moneda/billete y los importes asociados.
2. **Given** una sesión sin desglose registrado, **When** consulta el desglose, **Then** el
   sistema indica claramente que no hay denominaciones y no inventa importes.

---

### User Story 4 - Diario de anulaciones (Priority: P2)

Un supervisor consulta el diario cronológico de líneas de venta anuladas: artículo, cantidad,
importe, motivo, cajero, mesa/puesto, fecha y sesión. Filtra por fecha, cajero y motivo para
identificar quién anuló qué y por qué, sin consultar la base de datos directamente.

**Why this priority**: Criterio de éxito explícito de control interno; reduce dependencia de
soporte técnico/BD.

**Independent Test**: Para una fecha con anulaciones conocidas, el diario lista cada anulación
con cajero, artículo, motivo y puesto/sesión, filtrable por cajero y motivo.

**Acceptance Scenarios**:

1. **Given** anulaciones registradas en una fecha, **When** el usuario abre el diario y filtra
   por esa fecha, **Then** ve el listado cronológico con artículo, cantidad, importe, motivo,
   cajero, mesa/puesto, fecha y sesión.
2. **Given** varias anulaciones de distintos cajeros y motivos, **When** filtra por cajero o
   por motivo, **Then** el listado se reduce a las que cumplen el filtro.
3. **Given** un usuario con solo permiso de consulta, **When** consulta el diario,
   **Then** puede ver la información pero no modificar importes de la venta original.

---

### User Story 5 - Cobros y pagos asociados a ventas (Priority: P3)

Un usuario de administración consulta movimientos de cobro y pago ligados a ventas. El tipo
(Cobro o Pago) lo determina la configuración de la forma de pago usada en cada movimiento, no
tablas distintas. Filtra por tipo, forma de pago, fecha y puesto.

**Why this priority**: Complementa la consulta de ventas y el arqueo para análisis de
tesorería; no bloquea el cuadre diario ni la búsqueda de ventas.

**Independent Test**: Con movimientos de distintas formas de pago (unas cobro, otras pago), el
listado clasifica correctamente cada movimiento y los filtros por tipo/fecha/puesto/forma de
pago producen el subconjunto esperado.

**Acceptance Scenarios**:

1. **Given** movimientos de venta con formas de pago configuradas como cobro o como pago,
   **When** el usuario lista Cobros y Pagos, **Then** cada movimiento aparece clasificado
   según esa configuración.
2. **Given** el listado completo, **When** filtra por tipo Cobro (o Pago), forma de pago,
   fecha y puesto, **Then** solo se muestran movimientos que cumplen todos los filtros
   aplicados.
3. **Given** una venta cerrada, **When** el usuario consulta sus cobros/pagos desde este
   submenú, **Then** puede verlos pero no alterar los importes ya cobrados.

---

### User Story 6 - Liquidación de vales (Priority: P2)

Un usuario de mostrador o administración gestiona el ciclo de vida de vales/bonos: emisión,
consulta de pendientes y liquidación (marcar como liquidado con fecha y tipo de liquidación).
El sistema respeta la caducidad y bloquea liquidar vales caducados o ya liquidados.

**Why this priority**: Única escritura administrativa recurrente del módulo con impacto
económico directo; tercer criterio de éxito (imposibilidad de reliquidar o liquidar caducados).

**Independent Test**: Emitir un vale, liquidarlo correctamente; intentar liquidar el mismo vale
otra vez y un vale caducado — ambas operaciones deben bloquearse con mensaje claro.

**Acceptance Scenarios**:

1. **Given** un usuario con permiso de editar sobre Ventas (o la acción de liquidar vales),
   **When** emite un vale a un cliente con importe y caducidad válidos, **Then** el vale queda
   pendiente y consultable en el listado de pendientes.
2. **Given** un vale pendiente no caducado, **When** lo liquida indicando fecha y tipo de
   liquidación, **Then** el vale pasa a liquidado y deja de aparecer como pendiente.
3. **Given** un vale ya liquidado o con fecha de caducidad vencida, **When** intenta
   liquidarlo, **Then** el sistema bloquea la operación e informa del motivo (ya liquidado o
   caducado).

---

### User Story 7 - Pedidos de clientes e impresión (Priority: P3)

Un usuario da de alta y consulta pedidos de cliente (cabecera + líneas de artículos,
cantidades y precios), hace seguimiento de estado y puede marcar/reimprimir el pedido como
impreso (acción sobre el pedido existente, análoga al indicador «Impreso» de Ventas). Los
pedidos pueden derivar después en venta/albarán (fuera o en fase posterior; aquí se gestiona
el pedido y su estado).

**Why this priority**: Extiende el módulo hacia preventa/reserva; la impresión es una acción
sobre la misma entidad, no un mantenimiento nuevo.

**Independent Test**: Crear un pedido con líneas, consultarlo por estado, marcar como impreso
con permiso de edición y verificar que un usuario solo-lectura no puede marcar impresión ni
alterar el pedido de forma no autorizada.

**Acceptance Scenarios**:

1. **Given** un usuario con permiso de crear pedidos, **When** da de alta un pedido con
   cabecera y al menos una línea (artículo, cantidad, precio), **Then** el pedido queda
   registrado y consultable con su estado inicial.
2. **Given** pedidos existentes en distintos estados, **When** el usuario filtra o consulta
   por estado, **Then** ve el seguimiento correcto de cada pedido (cabecera y líneas).
3. **Given** un pedido existente y un usuario con permiso de editar (impresión), **When**
   solicita imprimir o marcar como impreso, **Then** el indicador de impreso se actualiza (y
   puede reimprimirse sin crear una entidad nueva).
4. **Given** un usuario con solo permiso de ver, **When** intenta marcar un pedido como
   impreso o liquidar un vale, **Then** la acción se deniega de forma visible.

---

### User Story 8 - Desglose de arqueo por puesto (Priority: P3)

El desglose «Registradora» no es una entidad ni pantalla distinta: es el mismo Desglose de
arqueo filtrado o preseleccionado por puesto (caja física). No existe en el legado un
concepto de registradora aparte del puesto de venta.

**Why this priority**: Evita un submenú duplicado; cubre el caso de uso operativo sin
ampliar el modelo de datos.

**Independent Test**: Filtrando Desglose de arqueo por un puesto concreto, el usuario obtiene
el detalle por denominación de esa caja/sesión sin menú adicional.

**Acceptance Scenarios**:

1. **Given** Desglose de arqueo disponible, **When** el usuario filtra (o selecciona) un
   puesto concreto, **Then** ve el desglose de esa caja sin un submenú «Registradora»
   separado.
2. **Given** varios puestos con arqueo en la misma sesión o día, **When** cambia el filtro
   de puesto, **Then** el desglose se actualiza al puesto elegido.
---

### Edge Cases

- Venta facturada vs no facturada: el detalle muestra el número de factura solo cuando existe;
  el filtro/estado debe distinguir facturada sin romper la consulta de la venta origen.
- Sesión de caja sin movimientos o solo con formas de pago que no cuentan para arqueo: el
  arqueo muestra ceros o vacío explícito, sin error opaco.
- Más de 20 tipos de denominación en datos legados: el desglose presenta como máximo 20 tipos
  según regla de negocio acordada y no falla al cargar.
- Vale con caducidad el día en curso: se considera no caducado mientras la fecha de caducidad
  sea mayor o igual al día de liquidación (día calendario del establecimiento), salvo que
  negocio defina otra regla.
- Pedido sin líneas: no se permite confirmar el alta hasta tener al menos una línea válida.
- Anulaciones sin motivo informado por TPV: el diario muestra el registro con motivo vacío o
  «Sin motivo» de forma consistente, sin ocultar la anulación.
- Usuario con ver Ventas pero sin editar: ve todos los submenús de consulta; no liquida vales ni
  marca pedidos como impresos.
- Intento de editar importes de venta cerrada desde cualquier submenú: siempre denegado.

## Requirements *(mandatory)*

<!--
  Constitution reminders (see .specify/memory/constitution.md):
  - Facturación: Veri*Factu + TicketBAI integrity (Principio V) — este módulo no emite
    facturas; solo consulta el vínculo venta↔factura ya existente.
  - New modules: configurable permissions from Mantenimiento (Principio VI)
  - Schema changes: justify in spec before implementation (Principio IV)
  - TPV features: offline-first sale path required (Principio II) — las ventas se originan
    en TPV; Gestión consume datos ya persistidos vía API.
-->

### Functional Requirements

- **FR-001**: El sistema MUST exponer un módulo de Ventas en Gestión con submenús: Ventas,
  Arqueo de caja, Desglose de arqueo, Diario de anulaciones, Cobros y Pagos, Liquidación de
  Vales y Pedido de Clientes (con acción de impresión/marcado Impreso sobre el pedido). No
  MUST existir un submenú separado «Desglose de arqueo Registradora».
- **FR-002**: El sistema MUST permitir listar y abrir el detalle de ventas realizadas en TPV,
  mostrando en cabecera al menos: cliente, fecha, vendedor, puesto, importes por tipo de IVA,
  formas de pago usadas, estado, si está facturada y número de factura asociada; y en líneas:
  artículo, cantidad, precio y descuentos.
- **FR-003**: El usuario MUST poder buscar y filtrar ventas por fecha, tienda/puesto,
  vendedor, cliente y estado.
- **FR-004**: El sistema MUST NOT permitir crear ventas nuevas ni editar importes ya cobrados
  de una venta cerrada desde Gestión.
- **FR-005**: El sistema MUST mostrar el arqueo por sesión de caja y puesto, incluyendo
  efectivo entrado, acumulado por formas de pago que cuentan para arqueo, y desglose por
  denominación de moneda/billete (hasta 20 tipos).
- **FR-006**: El total de arqueo de una sesión/puesto MUST cuadrar exactamente con la suma de
  los movimientos de esa sesión cuya forma de pago esté configurada como «cuenta para arqueo».
- **FR-007**: El sistema MUST ofrecer una vista de desglose de arqueo por denominación para
  una sesión y puesto concretos (cubre el caso de uso «registradora» vía filtro de puesto).
- **FR-008**: El sistema MUST listar cronológicamente las líneas de venta anuladas con
  artículo, cantidad, importe, motivo, cajero, mesa/puesto, fecha y sesión.
- **FR-009**: El usuario MUST poder filtrar el diario de anulaciones por fecha, cajero y
  motivo.
- **FR-010**: El sistema MUST listar movimientos de cobro y pago asociados a ventas,
  clasificando cada movimiento como Cobro o Pago según la configuración de la forma de pago
  utilizada (no como entidades/tablas de negocio distintas desde la perspectiva del usuario).
- **FR-011**: El usuario MUST poder filtrar Cobros y Pagos por tipo (cobro/pago), forma de
  pago, fecha y puesto.
- **FR-012**: El sistema MUST permitir emitir vales/bonos a clientes, consultar vales
  pendientes y liquidarlos registrando fecha y tipo de liquidación.
- **FR-013**: El sistema MUST bloquear la liquidación de vales caducados y de vales ya
  liquidados, informando el motivo del bloqueo.
- **FR-014**: El sistema MUST permitir alta, consulta y seguimiento de estado de pedidos de
  cliente con cabecera y líneas (artículo, cantidad, precio).
- **FR-015**: El sistema MUST permitir marcar como impreso o reimprimir un pedido de cliente
  existente sin crear una entidad de datos nueva (equivalente conceptual al indicador Impreso
  de Ventas).
- **FR-016**: Un usuario MUST necesitar permiso de «ver» sobre el módulo Ventas para acceder
  a cualquier submenú de este módulo.
- **FR-017**: Un usuario MUST necesitar permiso de «editar» (acción específica de escritura
  administrativa) para liquidar vales y para marcar pedidos como impresos.
- **FR-018**: Un usuario MUST necesitar permiso de «crear» para dar de alta pedidos de
  cliente desde Gestión.
- **FR-019**: El módulo MUST integrarse con el sistema de roles y permisos configurable de
  Mantenimiento (sin roles fijados en código).
- **FR-020**: «Desglose de arqueo Registradora» MUST unificarse con Desglose de arqueo
  mediante filtro/selección por puesto; MUST NOT introducirse un concepto de datos
  «registradora» distinto del puesto de venta.
- **FR-021**: El diario de anulaciones en Gestión MUST ser de solo consulta sobre registros
  originados en TPV; MUST NOT permitir alta ni edición de anulaciones (ni de motivos) desde
  Gestión en este MVP.
### Key Entities

- **Venta**: Operación de cobro ya realizada en TPV; cabecera (cliente, fecha/hora, vendedor,
  puesto/tienda, estado, facturación asociada, totales por IVA, formas de pago) y líneas
  (artículo, cantidad, precio, descuentos). De solo lectura en Gestión una vez cerrada.
- **Sesión de caja / Arqueo**: Conjunto de movimientos de una sesión en un puesto; totales de
  efectivo y por formas de pago que cuentan para arqueo; desglose por denominación
  (moneda/billete, hasta 20 tipos).
- **Anulación de línea**: Registro de una línea de venta anulada (artículo, cantidad, importe,
  motivo, cajero, puesto/mesa, fecha, sesión).
- **Movimiento de cobro/pago**: Aplicación de una forma de pago a una venta; el atributo de
  la forma de pago determina si se trata de cobro o de pago a efectos de consulta.
- **Forma de pago (configuración reutilizada)**: Maestro de Mantenimiento; indica si cuenta
  para arqueo y si se comporta como cobro o como pago.
- **Vale / bono**: Documento emitido a un cliente con importe, fechas de emisión/caducidad,
  estado (pendiente/liquidado) y, tras liquidar, fecha y tipo de liquidación.
- **Pedido de cliente**: Encargo con cabecera, estado, indicador de impreso y líneas de
  artículos; puede derivar posteriormente en venta/albarán.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario con permiso de consulta localiza cualquier venta del día actual por
  cliente, puesto o rango horario en como máximo 3 acciones de interfaz desde el submenú
  Ventas.
- **SC-002**: Para toda sesión de caja de prueba, el importe de arqueo mostrado coincide al
  céntimo con la suma de las formas de pago de esa sesión marcadas como «cuenta para arqueo».
- **SC-003**: El 100 % de los intentos de liquidar un vale caducado o ya liquidado resultan
  bloqueados con mensaje comprensible; ningún vale en ese estado queda marcado como liquidado
  de nuevo.
- **SC-004**: Para cualquier fecha con anulaciones, un supervisor identifica en el diario qué
  cajero anuló qué artículo y por qué motivo, sin necesidad de consultar la base de datos ni
  pedir ayuda a soporte técnico.
- **SC-005**: Usuarios sin permiso de ver Ventas no acceden a ningún submenú del módulo; usuarios
  solo-lectura no completan liquidación de vales ni marcado de impresión de pedidos.
- **SC-006**: En pruebas de aceptación, el alta de un pedido de cliente con líneas y el marcado
  como impreso se completan en menos de 2 minutos por un usuario formado.

## Clarifications

### Session 2026-07-22

- Q: ¿«Desglose de arqueo Registradora» es pantalla separada o se unifica con Desglose de
  arqueo? → A: Unificar con Desglose de arqueo + filtro por puesto (sin submenú ni entidad
  «registradora»). Decisión por asunción del spec al saltarse `/speckit.clarify`.
- Q: ¿El diario de anulaciones permite anotar desde Gestión? → A: Solo consulta de
  anulaciones originadas en TPV en este MVP. Decisión por asunción del spec al saltarse
  `/speckit.clarify`.

## Assumptions

- Las ventas, arqueos, anulaciones y movimientos de cobro/pago se originan principalmente en
  el programa de Venta/TPV y ya están disponibles vía la API compartida; Gestión no sustituye
  al TPV para cobrar.
- El maestro de formas de pago (incluidos «cuenta para arqueo» y clasificación cobro/pago)
  existe o se completa en Mantenimiento y este módulo solo lo consume.
- No existe en el esquema legado un concepto de «registradora» distinto del puesto de venta;
  «Desglose de arqueo Registradora» se cubre con Desglose de arqueo filtrado por puesto
  (FR-020).
- El diario de anulaciones es solo consulta; no hay alta ni edición de anulaciones desde
  Gestión (FR-021).
- La caducidad del vale se evalúa por fecha calendario del día de liquidación respecto a la
  fecha de caducidad del vale (vale caducado si la fecha de liquidación es posterior a la de
  caducidad).
- Los tipos de liquidación de vales son códigos de 1 carácter en el legado (`TipoLiquidacion`);
  en UI se exponen los valores distintos observados en datos / catálogo acordado, sin tabla
  nueva.
- «Impresión de pedidos» es una acción sobre Pedido de Clientes (reimprimir / marcar
  Impreso), no un mantenimiento ni entidad separada en el menú más allá de esa acción.
- La derivación pedido → venta/albarán puede quedar como seguimiento de estado o enlace
  informativo en esta fase; el cobro sigue ocurriendo en TPV.
- Idioma de interfaz y mensajes: español.
- No se requieren cambios de esquema incompatibles con Descartes 1.0; si hiciera falta algún
  campo, se justificará en el plan antes de implementarlo (Principio IV).
