# Feature Specification: Módulo de Compras (Gestión)

**Feature Branch**: `004-compras-gestion`  
**Created**: 2026-08-12  
**Status**: Draft  
**Input**: User description: "Abrir el módulo de Compras del programa de Gestión de Descartes 2.0, reutilizando el patrón de Ventas y las tablas legacy de albaranes/pedidos/facturas de compra."

## Contexto

En Descartes 1.0 las compras viven en SQL Server (misma BD por cliente):

| Concepto | Tablas legacy |
|----------|----------------|
| Albarán de compra | `AlbaranesCompraCab` + `AlbaranesComprasLin` |
| Pedido a proveedor | `PedidosCab` + `PedidosLin` |
| Factura de proveedor | `FacturasCompras` |
| Maestro | `Proveedores` (ya en Mantenimiento) |

En Gestión 2.0 el menú **Compras** existe como placeholder. Este spec define el MVP de Gestión (no TPV): entrada de mercancía, pedidos a proveedor y consulta de facturas de compra, con permisos configurables y plantillas A4 ya previstas en puestos (`FormatoAlbaranCompras`, `FormatoPedidoCompras`, impresoras asociadas).

**Fuera de alcance v1**: contabilidad/traspaso CTB, EDI/web de proveedores, transporte MRW, generación automática de pedidos por stock mínimo, OPOS de compras, **y la importación automática de factura/albarán de proveedor mediante IA/OCR** (queda como *estudio* más abajo; no forma parte del MVP implementable).

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Consultar albaranes de compra (Priority: P1)

Un responsable de almacén o administración localiza albaranes de compra ya registrados: listado con filtros (fecha, tienda/empresa, proveedor, almacén, nº albarán / «su albarán») y abre el detalle (cabecera: proveedor, fechas, importes, almacén, observaciones, estado; líneas: artículo, descripción, cantidad, precio, descuentos, lote, pedido origen si existe).

**Why this priority**: Es el documento operativo diario de entrada de mercancía y el análogo natural del listado de ventas. Sin consulta no hay trazabilidad de compras.

**Independent Test**: Con albaranes de compra existentes en BD, un usuario con permiso de ver Compras localiza un albarán por proveedor y fecha en como máximo tres acciones y ve cabecera y líneas coherentes con SQL.

**Acceptance Scenarios**:

1. **Given** un usuario autenticado con permiso de ver Compras y albaranes en el periodo, **When** filtra por fecha y proveedor, **Then** el listado muestra solo los que cumplen el filtro y puede abrir el detalle.
2. **Given** un albarán con varias líneas y descuentos, **When** abre el detalle, **Then** ve proveedor, fecha, almacén, importes (bruto/dto/IVA si están en cabecera), «su albarán», observaciones y líneas con artículo, cantidad, precio y descuentos.
3. **Given** un usuario sin permiso de ver Compras, **When** intenta acceder al listado, **Then** el acceso se deniega de forma visible.

---

### User Story 2 - Alta y edición de albarán de compra (Priority: P1)

Un usuario autorizado crea un albarán de compra (entrada de mercancía): elige tienda/empresa, proveedor, almacén, fecha, opcionalmente «su albarán» y líneas de artículo (cantidad, precio, dto). Puede modificar un albarán no bloqueado (p. ej. no traspasado a contabilidad / no facturado según reglas legacy de `Estado` / `TrasCtb`). Puede marcar devolución (`AlbaranDevolucion`) cuando corresponda.

**Why this priority**: Sin alta/edición el módulo solo sería consulta; en jardinería/garden center la recepción se registra a menudo en oficina/gestión.

**Independent Test**: Crear un albarán con proveedor y dos líneas; guardarlo; reabrirlo y ver los mismos datos; editar cantidad de una línea y guardar.

**Acceptance Scenarios**:

1. **Given** permiso de crear Compras, **When** el usuario da de alta cabecera (empresa, proveedor, almacén, fecha) y al menos una línea válida, **Then** el sistema asigna número de albarán (contador de tienda/legacy) y persiste cabecera + líneas.
2. **Given** un albarán editable, **When** modifica líneas o cabecera y guarda, **Then** los cambios persisten y los importes de cabecera se recalculan de forma coherente con las líneas.
3. **Given** un albarán bloqueado por reglas de negocio (traspasado / no editable), **When** intenta editar, **Then** el sistema lo muestra en solo lectura e informa el motivo.
4. **Given** un albarán de devolución, **When** se marca como tal, **Then** el indicador queda registrado y es visible en listado/detalle.

---

### User Story 3 - Pedidos a proveedor (Priority: P2)

Un comprador consulta y crea pedidos a proveedor (`PedidosCab` / `PedidosLin`): cabecera (proveedor, fecha, almacén, fecha máx. recepción, observaciones) y líneas (artículo, cantidad pedida, precio, dto). Consulta situación del pedido (pendiente / parcial / servido según `Situacion` y cantidades pedidas vs servidas).

**Why this priority**: Es el flujo previo a la recepción; aporta valor aunque el albarán pueda crearse sin pedido.

**Independent Test**: Alta de pedido con dos líneas; listado filtrado por proveedor; detalle con cantidades pedidas.

**Acceptance Scenarios**:

1. **Given** permiso de ver/crear pedidos de compra, **When** el usuario lista y filtra por proveedor y fechas, **Then** ve los pedidos correspondientes.
2. **Given** permiso de crear, **When** da de alta un pedido con líneas válidas, **Then** se asigna número de pedido y queda consultable.
3. **Given** un pedido con líneas parcialmente servidas (`CantidadSer` &lt; `CantidadPed`), **When** abre el detalle, **Then** ve claramente pedido vs servido por línea y la situación del pedido.

---

### User Story 4 - Recepción desde pedido (pedido → albarán) (Priority: P2)

Desde un pedido pendiente o parcial, el usuario genera (o completa) un albarán de compra con las cantidades a recibir, enlazando líneas al `Pedido` origen. Actualiza cantidades servidas del pedido.

**Why this priority**: Evita teclear dos veces el mismo surtido; es el flujo legacy habitual pedido→albarán.

**Independent Test**: Pedido con 10 uds; recepción de 4; albarán con 4; pedido queda parcial con 4 servidas; segunda recepción del resto.

**Acceptance Scenarios**:

1. **Given** un pedido con líneas pendientes, **When** el usuario lanza «Recibir» / «Generar albarán» e indica cantidades ≤ pendientes, **Then** se crea un albarán de compra vinculado y se actualizan `CantidadSer`.
2. **Given** intento de recibir más de lo pendiente, **When** confirma, **Then** el sistema rechaza o recorta según regla acordada e informa el error.
3. **Given** pedido totalmente servido, **When** intenta otra recepción, **Then** no se permite o se indica que no hay pendiente.

---

### User Story 5 - Consulta de facturas de proveedor (Priority: P3)

Administración consulta facturas de compra (`FacturasCompras`): listado por fecha, proveedor, nº factura / «su factura», estado; detalle con bases, IVA, vencimientos. En v1 es **consulta** (sin alta completa de factura proveedor ni conciliación contable).

**Why this priority**: Cierra el ciclo informativo compras; la facturación de proveedor completa puede ser fase 2.

**Independent Test**: Listar facturas de un mes y abrir una con vencimientos visibles.

**Acceptance Scenarios**:

1. **Given** facturas de compra en BD, **When** el usuario filtra por proveedor y rango de fechas, **Then** ve el listado y puede abrir el detalle.
2. **Given** una factura con varios vencimientos, **When** abre el detalle, **Then** ve bases/IVA y vencimientos (fechas e importes) en solo lectura.
3. **Given** v1, **When** el usuario busca alta de factura proveedor, **Then** no está disponible (o queda explícitamente fuera de alcance en UI).

---

### User Story 6 - Impresión A4 de documentos de compra (Priority: P3)

El usuario imprime albarán de compra o pedido a proveedor en A4 usando la plantilla e impresora configuradas en el puesto (Generales II: Albaranes compras / Pedidos compras), con previsualización (mismo patrón que ventas no-ticket).

**Why this priority**: Operativa de almacén/oficina; depende de US1–US3 pero reutiliza infraestructura ya hecha de plantillas.

**Independent Test**: Con plantilla e impresora asignadas en el puesto, desde un albarán de compra se abre preview A4 y se envía a la impresora del documento.

**Acceptance Scenarios**:

1. **Given** albarán de compra abierto y puesto con `FormatoAlbaranCompras` + impresora, **When** pulsa Imprimir, **Then** ve previsualización A4 y puede imprimir en la impresora configurada.
2. **Given** pedido a proveedor y formato/impresora de pedidos compras, **When** imprime, **Then** usa esa plantilla/impresora (no la de tickets).
3. **Given** sin plantilla en el puesto, **When** imprime, **Then** el sistema informa y ofrece esqueleto/activo por tipo o mensaje claro.

---

### Edge Cases

- Albarán sin líneas: no se permite guardar alta definitiva hasta tener al menos una línea de artículo válida.
- Proveedor de baja / inexistente: no se permite alta; el buscador solo muestra proveedores activos salvo consulta histórica.
- Artículo inexistente o de baja: la línea se rechaza o se avisa; no se inventan precios.
- «Su albarán» duplicado del mismo proveedor: aviso (no bloqueo duro en v1 salvo que negocio lo exija).
- Devolución de compra: cantidades negativas o flag `AlbaranDevolucion` según regla legacy documentada en plan; stock se tratará en implementación con la misma política que 1.0 (justificar en plan si hay side-effects).
- Pedido sin almacén / albarán sin almacén: obligatorio almacén de la tienda o el indicado en cabecera.
- Concurrencia: dos usuarios editando el mismo albarán — último guardado gana o se usa `upsize_ts`/optimistic lock si ya existe patrón en API.
- Usuario solo «ver»: consulta listados/detalle/impresión según permiso; no crea ni edita.
- Empresa/tienda distinta a la del puesto: el filtro de empresa respeta multi-tienda de la BD (cada fila `Empresas` = tienda).

## Requirements *(mandatory)*

<!--
  Constitution:
  - Permisos configurables (Principio VI)
  - Cambios de esquema justificados (Principio IV) — preferir tablas legacy sin columnas nuevas
  - Plantillas A4 de documentos (ya en Confeccionar documentos / puestos)
-->

### Functional Requirements

- **FR-001**: El sistema MUST exponer el módulo **Compras** en Gestión con submenús v1: Albaranes de compra, Pedidos a proveedor, Facturas de proveedor (consulta).
- **FR-002**: El sistema MUST listar y abrir detalle de albaranes de compra (`AlbaranesCompraCab` / `AlbaranesComprasLin`) con filtros por fecha, empresa/tienda, proveedor, almacén y número / su albarán.
- **FR-003**: El sistema MUST permitir crear y editar albaranes de compra no bloqueados, con cabecera y líneas, recalculando importes de cabecera de forma coherente.
- **FR-004**: El sistema MUST asignar el número de albarán de compra según el contador legacy de la tienda (equivalente a `UltAlbaran` / campo acordado en plan; MUST documentarse el contador exacto en `plan.md` / data-model).
- **FR-005**: El sistema MUST soportar el indicador de albarán de devolución y mostrarlo en listado/detalle.
- **FR-006**: El sistema MUST listar, crear y consultar pedidos a proveedor (`PedidosCab` / `PedidosLin`) con situación y cantidades pedidas/servidas.
- **FR-007**: El sistema MUST permitir generar un albarán de compra desde un pedido pendiente/parcial, actualizando cantidades servidas y enlazando `Pedido` en líneas.
- **FR-008**: El sistema MUST listar y mostrar en solo lectura facturas de proveedor (`FacturasCompras`) con bases, IVA y vencimientos.
- **FR-009**: El sistema MUST NOT implementar en v1 el alta completa de factura de proveedor ni el traspaso a contabilidad (`TrasCtb`).
- **FR-010**: El sistema MUST reutilizar el maestro de proveedores de Mantenimiento (búsqueda/selección).
- **FR-011**: El sistema MUST integrar permisos por módulo/acción (ver / crear / editar / eliminar) configurables desde Roles; módulo lógico `compras` (y submódulos si el plan los separa: `compras-albaranes`, `compras-pedidos`, `compras-facturas`).
- **FR-012**: El sistema MUST permitir imprimir albarán de compra y pedido a proveedor en A4 con plantilla e impresora del puesto (mismo patrón de preview que ventas no-ticket).
- **FR-013**: Eliminar albarán o pedido MUST estar restringido a documentos no bloqueados y a permiso eliminar; MUST registrar o impedir borrado si hay líneas servidas / enlaces (regla en plan).
- **FR-014**: La API MUST seguir el estilo REST de `descartes-api` (`/api/compras/...`) con sesión y `PermissionMiddleware`.
- **FR-015**: La UI MUST seguir el patrón de Gestión (listado + ficha + toolbar), alineado a Ventas/Mantenimiento.

### Key Entities

- **Albarán de compra**: documento de entrada (o devolución) de mercancía de un proveedor a un almacén/tienda.
- **Línea de albarán de compra**: artículo, cantidades, precios, descuentos, lote, pedido origen.
- **Pedido a proveedor**: compromiso de compra; líneas con cantidad pedida y servida.
- **Factura de proveedor**: documento económico de compra (consulta v1).
- **Proveedor**: maestro ya existente en Mantenimiento.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un usuario entrenado localiza un albarán de compra del día por proveedor en ≤ 3 acciones desde el menú Compras.
- **SC-002**: Alta de albarán con 5 líneas se completa en una sola sesión de ficha sin errores de guardado en entorno de prueba.
- **SC-003**: Recepción parcial de pedido deja cantidades servidas correctas y permite una segunda recepción del resto.
- **SC-004**: Usuario sin permiso ver Compras no accede a ninguna ruta `/compras*`.
- **SC-005**: Impresión A4 de albarán de compra usa la impresora/plantilla de «Albaranes compras» del puesto (no la de tickets).

## Assumptions

- Se reutilizan tablas legacy sin renombrar; el mapeo API camelCase ↔ columnas SQL se documentará en `data-model.md`.
- El efecto en stock al guardar albarán de compra seguirá la política de Descartes 1.0 (detalle en research/plan); si 1.0 actualiza stock al grabar, 2.0 MUST hacerlo igual o justificar desviación.
- Multi-tienda: `Empresa` en cabeceras = código de tienda (`Empresas.Codigo`).
- Plantillas A4 de compra pueden ser del tipo genérico del diseñador hasta existir tipos dedicados; el puesto ya guarda el nombre en `FormatoAlbaranCompras` / `FormatoPedidoCompras`.
- v1 no incluye app móvil ni TPV de compras.
- v1 no incluye lectura automática de facturas/albaranes de proveedor por IA; solo el estudio documentado en este spec.

## Estudio (no MVP): importación automática desde documento del proveedor

**Estado**: solo estudio / fase futura. **MUST NOT** implementarse en v1 de Compras.

### Idea de negocio

El usuario dispone de la **factura o albarán del proveedor** (PDF o imagen). Quiere **adjuntarla** para que el programa la lea y proponga (o genere) la entrada de compra — cabecera y muchas líneas — evitando teclear albaranes largos.

No es lo mismo que “actualizar stock de un albarán ya grabado”; es **captura + interpretación del documento externo** del proveedor.

### Flujo conceptual (si se abordara más adelante)

1. Adjuntar PDF/imagen del documento del proveedor.
2. Extraer texto/datos (OCR y/o servicio de IA en la nube).
3. Proponer proveedor, fecha, nº documento y líneas (código/descripción, cantidad, precio, IVA).
4. **Revisión humana obligatoria** y confirmación.
5. Crear albarán de compra (y, según reglas, entrada de stock / `Actualizado`).

### Hosting e infraestructura (conclusiones preliminares)

| Opción | Qué implica | Encaje con Descartes 2.0 |
|--------|-------------|---------------------------|
| **IA en la nube** (Azure Document Intelligence, OpenAI, Google Document AI, etc.) | API key, coste por documento, salida HTTPS desde el servidor propio (API PHP) o desde Electron | Compatible con: web en hosting contratado + API/BD en servidor propio. **No exige Node en el hosting web.** |
| **IA self-hosted** | Máquina dedicada (mejor GPU), otro proceso (Python/Ollama/…) | Más coste operativo; solo si se rechaza nube. |

Producción recordatorio (independiente de este estudio):

1. Hosting contratado → web Gestión.  
2. Servidor propio → API PHP + SQL Server (BD por cliente).  
3. Electron en cada PC de puesto (periféricos/impresión).

### Riesgos a estudiar

- Emparejar artículos del proveedor con el maestro interno (códigos distintos).
- Calidad variable de PDF/escaneos → errores de lectura.
- Coste y privacidad (datos fiscales del proveedor en terceros).
- Nunca grabar stock a ciegas sin confirmación.

### Entregable del estudio (cuando se abra)

Un `research-importacion-documento-proveedor.md` con: proveedor(es) de IA evaluados, coste estimado, flujo UX de revisión, y decisión go/no-go. **Sin tareas de implementación en `tasks.md` de v1.**

## Open Questions

1. ¿El contador de albarán de compra es `Empresas.UltAlbaranCompra` (o similar)? Confirmar columna exacta en BD real antes de implementar FR-004.  
   → **Research**: `UltAlbaranCom` / `UltPedidoCom` / `UltAlbaranDevCom` (ver R-001). Validar devoluciones en BD real.
2. ¿Borrado físico vs anulación lógica de albaranes de compra en 1.0?  
   → **Propuesta**: borrado físico solo si `Actualizado=0` y `TrasCtb=0` (R-008).
3. ¿Facturas de compra v1 solo consulta es aceptable, o hace falta alta mínima (cabecera + vincular albaranes) en el mismo sprint?  
   → **Propuesta**: solo consulta (R-006 / FR-009).
4. ¿Permisos: un solo módulo `compras` o tres submódulos como en facturación?  
   → **Propuesta**: un módulo `compras` (R-008).
5. *(Estudio IA)* ¿Prioridad temporal del estudio tras estabilizar albaranes/pedidos manuales, o más adelante?  
   → **Propuesta**: tras estabilizar manuales.

*(1–4 cerradas como propuesta en [research.md](./research.md); confirmar contigo antes de `tasks.md`.)*
