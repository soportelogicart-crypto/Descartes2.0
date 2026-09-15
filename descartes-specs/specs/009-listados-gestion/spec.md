# Feature Specification: Módulo de Listados (Gestión)

**Feature Branch**: `009-listados-gestion`  
**Created**: 2026-09-15  
**Status**: Draft  
**Input**: User description: "Empezar a trabajar con los listados. Mirar legacy, pero no tiene que ser igual: buscar la mejor manera, fácil e intuitiva. Hacer un spec."

## Contexto

En Descartes 1.0 «Listados» es un **menú enorme** más un **formulario genérico** (`frmListado`):

- Catálogo Access `Informes.mdb` (tabla `Informes`: código, descripción, fichero `.rpt`).
- Motor **Crystal Reports** (`Listado.cls` / CRPEAuto).
- Hasta **10 combos** y **17 pares desde/hasta** en la misma pantalla, visibles según el informe.
- Acciones: Imprimir, Configurar salida, Borrar, navegación de informes.
- El mismo formulario se abre desde el menú Listados **y** desde Compras/Ventas/Inventario/Facturación (ABC, stock, IVA, extractos, etc.).

En Gestión 2.0:

| Pieza | Estado |
|-------|--------|
| Menú **Listados** → `/listados` | Placeholder |
| Permiso módulo `listados` | Existe, sin pantallas |
| ABC Ventas | Ya en `/ventas/abc` (muchos rangos legado + grid) |
| Diario de facturación | Filtros + grid + Excel + PDF |
| Botón **Listado** en mantenimientos | `window.print()` de la ficha (no es un informe) |
| Impresión documentos | Plantillas HTML + preview + impresora del puesto |

Este spec define el **módulo Listados de Gestión**: un **catálogo buscable** y un **asistente por informe** (filtros útiles → ver en pantalla → Excel / PDF / imprimir). No se porta Crystal ni `Informes.mdb`.

**Fuera de alcance v1**

- Crystal Reports / `.rpt` / `Informes.mdb`.
- Replicar los ~80 informes 1.0 uno a uno.
- Diseñador visual de informes (el de documentos A4 no se convierte en Crystal).
- Envío por email del listado (existe en 1.0 vía Crystal; más adelante).
- Informes de registradora, envíos/transporte, escandallos, fabricación, conciliación de tarjetas.
- Inventario operativo (congelar / capturar / actualizar) — solo **consulta** de stock si entra en v1.
- TPV: los listados son de Gestión.

---

## Decisión de producto (no clonar 1.0)

El problema de 1.0 no es la lista de informes: es **encontrarlos** y **rellenar un formulario que parece el mismo para todos**. En 2.0:

1. **Un informe = una ficha clara**, no 17 cajas vacías.
2. **Agrupar por** sustituye menús gemelos (ABC Ventas × 15, Stock × 6, ABC Compras × 8).
3. **Ver en pantalla antes de imprimir.** Imprimir a ciegas es lo que más quejas genera en Crystal.
4. **Excel es tan importante como el papel** (CSV `;` + BOM + coma decimal, igual que el resto de 2.0).
5. Las **operaciones** (arqueo, diario de facturación, pendientes de stock) se quedan en su módulo. Listados **enlaza** a ellas; no las duplica.

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Encontrar un listado (Priority: P1)

Un usuario de oficina abre **Listados**, busca por nombre o recorre categorías (Maestros, Ventas, Compras, Stock, Fiscal) y entra en un informe. Ve los que ha usado hace poco.

**Why this priority**: Sin catálogo usable el módulo no existe. El menú 1.0 mezcla maestros, ABC y stock en sitios distintos; hay que unificar la entrada.

**Independent Test**: Con permiso `listados`, en ≤ 3 acciones se abre un informe concreto (p. ej. Stock o ABC) desde el hub.

**Acceptance Scenarios**:

1. **Given** usuario con permiso de ver Listados, **When** abre Listados, **Then** ve un hub con categorías, buscador y (si hay) recientes; no un menú de 40 líneas sin filtro.
2. **Given** escribe «stock» o «abc» en el buscador, **Then** solo aparecen los informes cuyo título o palabras clave coinciden (sin acentos).
3. **Given** usuario sin permiso `listados`, **When** entra a `/listados`, **Then** se deniega el acceso de forma visible.
4. **Given** un informe que ya vive en otro módulo (ABC Ventas, Diario de facturación), **When** lo elige en el hub, **Then** abre esa misma pantalla (misma ruta), no una copia.

---

### User Story 2 - Filtrar, ver y sacar el resultado (Priority: P1)

El usuario elige un informe, rellena **pocos filtros visibles** (fechas con atajos, tienda, y 1–2 criterios principales), pulsa **Generar**, ve una **tabla con totales**, y puede **Excel**, **PDF / vista previa** o **Imprimir**.

**Why this priority**: Es el trabajo diario. Si hay que imprimir para enterarse, el diseño ha fallado.

**Independent Test**: Stock o ABC con fechas del año en curso: grid con filas, pie de totales, CSV descargable, preview imprimible.

**Acceptance Scenarios**:

1. **Given** un informe de periodo, **When** abre la ficha, **Then** las fechas por defecto son razonables (p. ej. año en curso o mes actual) y hay atajos Hoy / Mes / Año.
2. **Given** filtros válidos, **When** pulsa Generar, **Then** ve filas en pantalla (o mensaje «Sin datos») y los totales del pie coinciden con la suma visible.
3. **Given** hay filas, **When** exporta Excel, **Then** obtiene CSV con BOM, `;` y decimales con coma, abrible en Excel.
4. **Given** hay filas, **When** pide vista previa o imprimir, **Then** ve un A4 con cabecera (título, empresa, filtros usados, fecha de emisión) y puede imprimir con el flujo ya usado en documentos.
5. **Given** el informe admite agrupación (ABC, stock), **When** cambia «Agrupar por», **Then** no cambia de menú: se regenera el mismo informe con otra dimensión.

---

### User Story 3 - Filtros avanzados sin asustar (Priority: P2)

Quien necesita rangos de familia, cliente o artículo los encuentra en **Más filtros** (cerrado por defecto). Los códigos tienen **lupa / buscar entidad**, no solo cajas de texto.

**Why this priority**: El poder de 1.0 hay que conservarlo; la pantalla inicial no.

**Independent Test**: ABC o stock: generar solo con fechas; luego abrir Más filtros, elegir un cliente/familia con buscador, regenerar y ver menos filas.

**Acceptance Scenarios**:

1. **Given** la ficha de un informe con muchos criterios 1.0, **When** se abre, **Then** Más filtros está plegado y la zona principal cabe sin scroll absurdo.
2. **Given** un criterio de maestro (cliente, artículo, proveedor), **When** usa la lupa, **Then** elige en el buscador de entidad ya existente.
3. **Given** Desde > Hasta en un rango, **When** genera, **Then** error claro y no se llama a la API.

---

### User Story 4 - Maestros: listado desde el mantenimiento (Priority: P2)

En Clientes, Artículos, Proveedores, etc. el botón **Listado** deja de ser `window.print()` de la ventana. Exporta o imprime **las filas del grid** (respetando filtros de columna), en el mismo formato Excel/PDF del módulo Listados.

**Why this priority**: En 1.0 hay un submenú entero de «listados de maestros»; en 2.0 el usuario ya está en el mantenimiento. No hace falta otro sitio para «imprimir clientes».

**Independent Test**: En el grid de clientes, filtrar una columna, pulsar Listado, obtener Excel o preview con esas filas.

**Acceptance Scenarios**:

1. **Given** un mantenimiento con grid y botón Listado, **When** pulsa Listado, **Then** puede elegir Excel o vista previa; no imprime el chrome de la aplicación.
2. **Given** filtros de columna activos, **Then** el listado usa el conjunto filtrado, no toda la tabla.
3. **Given** el hub de Listados, **When** busca «clientes» o «artículos», **Then** puede aparecer un acceso que lleva al mantenimiento (no un Crystal clonado).

---

### Edge Cases

- Cero filas: mensaje claro, Excel/imprimir deshabilitados.
- Demasiadas filas (p. ej. > 10 000): avisar, permitir Excel igual, no colgar el navegador; paginar o virtualizar el grid si hace falta.
- Informe sin permiso específico (p. ej. `ventas-abc`): no aparece en el hub o se deniega al abrirlo.
- Generar dos veces seguidas: cancela o ignora la petición anterior (no mezclar resultados).
- Tienda del puesto: por defecto filtrar a la tienda del equipo si el informe es de operaciones; permitir «todas» si el usuario puede.
- Caracteres raros / acentos en buscador del hub: misma normalización NFD que el resto de grids.

---

## Catálogo v1 (qué sí y qué no)

Un **informe** en 2.0 = id estable + título + categoría + filtros declarados + consulta API + columnas.

### Incluidos en v1 (MVP)

| Id | Título | Categoría | Notas |
|----|--------|-----------|--------|
| `abc-ventas` | ABC de ventas | Ventas | Ya existe. El hub enlaza a `/ventas/abc`. UX: compactar rangos en Más filtros (misma US2/US3). |
| `stock` | Stock | Stock | Un informe; **Agrupar por**: artículo, familia, subfamilia, macrofamilia, agrupación, proveedor. |
| `stock-minimos` | Stock bajo mínimos | Stock | Artículos por debajo del mínimo. |
| `extracto-clientes` | Extracto de clientes | Ventas / cobros | Movimientos / saldo en un periodo. |
| `informe-tickets` | Informe de tickets / diario de ventas | Ventas | Equivalente útil de Informe de Tickets / Diario de ventas 1.0 (cabecera, no receta Crystal). |
| `informe-iva` | Informe de IVA | Fiscal | Ventas por tipo de IVA en un periodo. |

El hub **también lista accesos** (no son informes nuevos):

- Diario de facturación → `/facturacion/diario`
- Diario de anulaciones, cobros y pagos, arqueo → rutas Ventas ya hechas
- Pendientes de stock (compras) → bandeja ya hecha

### Explicitamente no v1 (se quedan catalogados para más adelante)

ABC compras, traspasos, mermas, movimientos de almacén, situación de recibos, inventario valorado, precios artículo-proveedor, listados Crystal de envíos, ofertas, fidelización avanzada, registradora.

---

## UX del hub y de la ficha

### Hub `/listados`

- Título **Listados**.
- Buscador arriba (placeholder: «Buscar informe: stock, abc, iva…»).
- Recientes (últimos 8, `localStorage` por usuario+equipo).
- Categorías en tarjetas o grupos: Maestros (accesos), Ventas, Compras, Stock, Fiscal / cobros.
- Cada ítem: título + una línea de para qué sirve. Sin códigos 1.0 (`0030`, `3029`…).

### Ficha de informe (plantilla común)

```
[← Listados]   Título del informe
Filtros principales (fechas + tienda + agrupar por)
[Más filtros ▾]
[Generar]  [Excel]  [Vista previa]  [Imprimir]
Grid + filtros de columna + pie de totales
```

- **Generar** es la acción primaria (fondo `#0f172a`).
- Excel / preview / imprimir deshabilitados hasta haber generado con éxito.
- No reutilizar el formulario ABC actual con 15 rangos siempre visibles: hay que **plegarlo** al adoptar la plantilla (el endpoint puede quedarse).

### Agrupar por (regla)

Si 1.0 tenía N entradas que solo cambian el `GROUP BY`, en 2.0 hay **una** entrada y un select:

- ABC ventas: vendedor, artículo, familia, cliente, … (las dimensiones que ya soporta la API).
- Stock: artículo, familia, … (tabla v1).

---

## Requirements *(mandatory)*

- **FR-001**: MUST existir ruta `/listados` real (no placeholder) con hub buscable y permiso `listados` / `ver`.
- **FR-002**: MUST existir un catálogo declarativo en frontend (`listados-nav` o equivalente) con id, título, categoría, palabras clave, ruta o id de informe, y módulo de permiso.
- **FR-003**: Informes de consulta MUST pasar por la API (`/api/listados/...` o endpoints ya existentes). El navegador MUST NOT hablar con SQL.
- **FR-004**: MUST NOT usarse Crystal Reports ni leer `Informes.mdb`.
- **FR-005**: Cada informe v1 MUST mostrar resultado en grid antes de exportar o imprimir.
- **FR-006**: Exportación Excel MUST ser CSV UTF-8 con BOM, separador `;`, decimal `,`.
- **FR-007**: Impresión / PDF MUST reutilizar el preview A4 de Gestión (cabecera con título, tienda, periodo, usuario, fecha/hora).
- **FR-008**: Informes con variantes de agrupación MUST ser uno solo + `agruparPor`.
- **FR-009**: Filtros no esenciales MUST ir en «Más filtros» cerrado por defecto.
- **FR-010**: Rangos de maestro SHOULD usar el buscador de entidad existente.
- **FR-011**: Accesos a pantallas ya construidas MUST abrir la ruta actual, sin duplicar lógica.
- **FR-012**: Botón Listado de mantenimientos (P2) MUST exportar/imprimir el grid filtrado, no `window.print()` de la app.
- **FR-013**: Consultas MUST acotarse por `Empresa` / tienda según el modelo legacy y el puesto.
- **FR-014**: Volumen grande MUST avisar; el API SHOULD limitar o paginar de forma explícita (no timeouts mudos).
- **FR-015**: Permisos: hub = `listados`; cada informe usa su módulo si ya existe (`ventas-abc`, etc.) o `listados` hasta desglosar.

### Key Entities

- **Informe (catálogo)**: metadatos (id, título, categoría, filtros, columnas). No es una tabla SQL v1; vive en código.
- **Ejecución**: filtros + resultado (filas + totales) de una pulsación Generar. No se persiste en BD v1.
- **Reciente**: id de informe + timestamp en cliente.

No se crea tabla `Listados` en SQL v1 (Principio IV: no hace falta para el MVP).

---

## Success Criteria *(mandatory)*

- **SC-001**: Un usuario que en 1.0 iba a Listados → Artículos / Stock llega en 2.0 al stock agrupado por artículo en ≤ 3 clics desde el menú Listados.
- **SC-002**: El 100 % de los informes v1 se pueden consultar en pantalla sin imprimir.
- **SC-003**: Excel de un informe v1 abre en Excel con columnas alineadas (`;` / coma decimal).
- **SC-004**: No hay menú separado «ABC Ventas (Familias)», «ABC Ventas (Artículos)», etc.; hay un ABC con agrupación.
- **SC-005**: Usuario sin `listados` no ve el módulo; usuario sin `ventas-abc` no ejecuta ABC aunque vea el hub (o no ve la tarjeta).

---

## Assumptions

- El valor de 1.0 está en **las consultas SQL y los criterios**, no en Crystal ni en el formulario genérico.
- Diario de facturación, arqueo, anulaciones y pendientes de stock **ya cubren** esos informes operativos; el hub solo enlaza.
- Plantillas HTML de documentos **no** se reutilizan como diseñador de listados v1: el A4 de listado es un layout fijo (cabecera + tabla + totales).
- Multi-tienda: `Empresa` = código de tienda, igual que el resto de 2.0.
- Idioma: castellano en UI v1 (ABC ya tiene campo idioma legado; no es prioridad del hub).

---

## Open Questions

1. ¿El ABC de ventas se **mueve** de Ventas a Listados en el menú, o se deja en los dos sitios (Ventas + hub)?  
   → **Propuesta**: los dos (atajo en Ventas, ficha canónica enlazada desde el hub).
2. ¿Stock valorado (cantidad × coste/PVP) entra en v1 o solo unidades?  
   → **Propuesta**: unidades + coste + PVP si las columnas existen en `Stock` / artículos; si el cálculo 1.0 es oscuro, solo unidades en v1.
3. ¿Permiso único `listados` o un módulo por informe desde el día uno?  
   → **Propuesta**: hub `listados`; reutilizar módulos ya existentes; no explotar la matriz de permisos con 40 filas en v1.
4. ¿Extracto de clientes es P1 o puede esperar a cobros/recibos más maduros?  
   → **Propuesta**: P1 si la consulta 1.0 es localizable en SQL; si no, sustituir en v1 por informe de tickets + IVA.
5. ¿El botón Listado de mantenimientos va en el mismo sprint que el hub?  
   → **Propuesta**: mismo spec, sprint 2 (P2), para no bloquear el hub.

*(Propuestas listas para confirmar antes de `tasks.md`.)*
