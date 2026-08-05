# Feature Specification: Módulo de Mantenimiento (Gestión)

**Feature Branch**: `001-mantenimiento-gestion`  
**Created**: 2026-07-08  
**Status**: Draft  
**Input**: User description: "Construir el módulo de Mantenimiento del programa de Gestión de Descartes 2.0…"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Datos fiscales del cliente (Priority: P1)

Un administrador accede al mantenimiento de **Empresa** (cliente) para consultar y editar los
datos fiscales y de contacto del cliente que posee esta base de datos (NIF, razón social,
dirección, régimen fiscal aplicable —común o País Vasco/TicketBAI—, divisa y contadores de
documentos). No existe listado ni alta de «empresas cliente»: cada base de datos SQL Server
pertenece a un único cliente (Principio III). Los datos se leen y escriben sobre la fila de
la tabla legacy `[Empresas]` con `Central = 1` (tienda/sede principal).

**Why this priority**: Sin datos fiscales del cliente correctamente configurados no existe
base para facturación conforme a Veri*Factu o TicketBAI. Es el primer paso de configuración.

**Independent Test**: Editar el perfil fiscal del cliente con régimen común o País Vasco en
dos entornos de prueba distintos (dos BDs); verificar que los datos capturados en la fila
central son suficientes para identificar fiscalmente al cliente y distinguir el régimen
aplicable, sin intervención de soporte técnico.

**Acceptance Scenarios**:

1. **Given** un administrador autenticado con permiso de edición sobre Empresa, **When**
   actualiza NIF, razón social, dirección y régimen fiscal común en la fila central,
   **Then** los datos quedan persistidos y disponibles para facturación conforme a Veri*Factu.
2. **Given** la fila central (`Central = 1`), **When** el administrador intenta darla de
   baja o eliminarla, **Then** el sistema impide la operación (la sede central no admite baja).
3. **Given** facturas ya emitidas para el cliente, **When** el administrador intenta cambiar
   NIF o régimen fiscal crítico, **Then** el sistema bloquea o exige confirmación sin romper
   trazabilidad Veri*Factu/TicketBAI.

---

### User Story 2 - Usuarios, roles y permisos configurables (Priority: P1)

Un administrador gestiona usuarios internos, asigna un rol a cada usuario y define roles
como conjuntos de permisos (ver, crear, editar, eliminar/dar de baja) por módulo del
sistema. Los roles no están predefinidos: se crean y editan libremente desde este módulo.

**Why this priority**: El sistema de permisos configurables es requisito constitucional y
habilita el control de acceso para todos los módulos futuros. Los criterios de éxito del
proyecto dependen directamente de esta historia.

**Independent Test**: Crear un rol limitado a un solo módulo (solo lectura en Artículos),
asignarlo a un usuario y comprobar que ese usuario no puede modificar artículos aunque
acceda al resto del sistema según su rol.

**Acceptance Scenarios**:

1. **Given** un administrador con permiso sobre Usuarios y Roles, **When** crea un rol con
   permiso de solo lectura sobre Artículos y asigna ese rol a un usuario, **Then** el
   usuario puede ver artículos pero no crear, editar ni dar de baja ningún artículo.
2. **Given** un rol en uso por usuarios activos, **When** el administrador intenta
   eliminarlo o restringir permisos de forma incompatible, **Then** el sistema advierte
   del impacto y aplica reglas de integridad sin dejar usuarios sin acceso definido.
3. **Given** un administrador sin permiso de edición sobre un módulo, **When** intenta
   acceder a la acción de edición de ese módulo, **Then** el sistema deniega la acción de
   forma visible (acción no disponible o mensaje claro de permiso insuficiente).

---

### User Story 3 - Tiendas, almacenes y puntos de venta (Priority: P2)

Un administrador configura **tiendas** (locales/sucursales físicos; cada una es una fila de
la tabla legacy `[Empresas]`) y las asocia a uno o varios **almacenes**. Gestiona también el
mantenimiento de almacenes como ubicaciones de stock.

**Why this priority**: Las tiendas y almacenes conectan la estructura organizativa con
inventario y TPV. Son prerequisito para operaciones de venta y stock en módulos posteriores.

**Independent Test**: Dar de alta una tienda (nueva fila en `[Empresas]`), un almacén y
vincularlos; verificar que la tienda queda operativa en listados y que la relación
tienda-almacén es consultable y editable.

**Acceptance Scenarios**:

1. **Given** el cliente ya configurado (fila central), **When** el administrador da de alta
   una tienda y la vincula a un almacén, **Then** ambos registros quedan activos y la
   relación es visible en sus formularios de detalle.
2. **Given** un almacén con stock o movimientos registrados, **When** se intenta dar de
   baja, **Then** el sistema bloquea la baja e indica la dependencia.
3. **Given** un listado de varias tiendas en la BD del cliente, **When** el administrador
   aplica filtros por código o nombre de tienda, **Then** solo ve las tiendas que coinciden
   con el criterio.

---

### User Story 4 - Maestros comerciales: artículos, clientes y proveedores (Priority: P2)

Un usuario autorizado mantiene los catálogos comerciales principales: artículos (código,
descripción, familia, precio de venta, impuesto, proveedor habitual, control de stock por
almacén), clientes y proveedores (datos fiscales y de contacto). Para cada entidad aplica
el patrón común de listado con búsqueda y filtros, alta, edición y baja lógica con
validación de dependencias.

**Why this priority**: Artículos, clientes y proveedores son los maestros que alimentan
Compras, Ventas, Facturación e Inventario. Sin ellos los módulos transaccionales no pueden
operar.

**Independent Test**: Dar de alta un artículo con impuesto y proveedor habitual, un cliente
y un proveedor; editar el precio del artículo; intentar dar de baja un artículo con ventas
asociadas y verificar el bloqueo.

**Acceptance Scenarios**:

1. **Given** catálogos de impuestos y proveedores existentes, **When** el usuario crea un
   artículo con precio, impuesto y control de stock por almacén, **Then** el artículo
   aparece en listados y conserva sus atributos comerciales.
2. **Given** un artículo con ventas o movimientos de stock, **When** el usuario intenta
   darlo de baja, **Then** el sistema impide la baja lógica e informa del motivo.
3. **Given** un usuario con permiso de edición sobre Clientes pero no sobre Proveedores,
   **When** accede a proveedores, **Then** solo puede consultar o no acceder a acciones
   de modificación según los permisos de su rol.

---

### User Story 5 - Personal, puestos y catálogos auxiliares (Priority: P3)

Un administrador gestiona trabajadores (personal de la empresa, con o sin usuario de acceso),
puestos de trabajo operativos (ej. caja, almacén) asociables a trabajadores o usuarios, e
impuestos y formas de pago como catálogos de soporte reutilizables por el resto del sistema.

**Why this priority**: Completan el mantenimiento inicial. Son necesarios para operación de
TPV y transacciones, pero pueden configurarse después de la estructura organizativa y los
maestros comerciales principales.

**Independent Test**: Crear un trabajador sin usuario, un puesto de caja, asociar el puesto
a un usuario existente, y definir un tipo impositivo y una forma de pago; verificar que
quedan disponibles en selecciones de otros mantenimientos.

**Acceptance Scenarios**:

1. **Given** un trabajador activo sin usuario asignado, **When** el administrador edita
   sus datos o le asocia un usuario opcionalmente, **Then** el trabajador queda disponible
   para asignación a puestos.
2. **Given** un puesto de trabajo en uso en sesiones o operaciones, **When** se intenta
   dar de baja, **Then** el sistema bloquea la operación.
3. **Given** un impuesto referenciado por artículos activos, **When** se intenta dar de
   baja, **Then** el sistema impide la baja e indica los artículos dependientes.
4. **Given** una forma de pago en uso en cobros registrados, **When** se intenta dar de
   baja, **Then** el sistema impide la baja lógica.

---

### Edge Cases

- ¿Qué ocurre si se intenta crear dos tiendas con el mismo código? El sistema debe rechazar
  el duplicado con mensaje claro.
- ¿Qué ocurre si se intenta duplicar el NIF en la fila central del cliente? El sistema debe
  validar coherencia fiscal con mensaje claro.
- ¿Qué ocurre si un usuario pierde todos los permisos de un módulo? No debe ver acciones
  de escritura ni pantallas de edición para ese módulo.
- ¿Qué ocurre al buscar con criterios que no devuelven resultados? Mostrar listado vacío
  con mensaje informativo, sin error técnico.
- ¿Qué ocurre si se edita el régimen fiscal del cliente con facturas ya emitidas? El
  sistema debe advertir del impacto fiscal y restringir o exigir confirmación explícita
  según reglas de integridad (sin permitir ruptura de trazabilidad Veri*Factu/TicketBAI).
- ¿Qué ocurre al rehabilitar un registro dado de baja? Fuera de alcance en v1 salvo que
  se documente explícitamente; por defecto la baja lógica es reversible solo por
  administrador con permiso de edición, manteniendo el mismo identificador.
- ¿Qué ocurre si un artículo tiene stock en un almacén pero no ventas? La baja sigue
  bloqueada si existe stock distinto de cero o movimientos de inventario asociados.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: El sistema MUST ofrecer un módulo de Mantenimiento accesible desde Gestión
  para usuarios con permiso correspondiente.
- **FR-002**: El sistema MUST permitir mantener las siguientes entidades:
  - **Empresa (cliente)**: solo consulta y edición de datos fiscales (singleton, fila
    `Central=1` de `[Empresas]`); sin listado, alta ni baja.
  - **Tiendas, Usuarios, Roles, Trabajadores, Puestos de trabajo, Artículos, Clientes,
    Proveedores, Almacenes, Impuestos y Formas de pago**: listado (búsqueda y filtros
    básicos), alta, edición y baja lógica. Las tiendas operan sobre la tabla legacy
    `[Empresas]` (cada fila = sucursal).
- **FR-003**: El sistema MUST aplicar baja lógica en todas las entidades; ningún registro
  maestro puede eliminarse físicamente desde la interfaz de Mantenimiento.
- **FR-004**: El sistema MUST impedir la baja lógica de un registro que tenga documentos,
  movimientos o relaciones activas dependientes, informando al usuario del motivo del bloqueo.
- **FR-005**: El sistema MUST capturar para la **Empresa (cliente)** —fila central—, como
  mínimo: NIF, razón social, dirección, régimen fiscal (común o País Vasco/TicketBAI),
  divisa y contadores de documentos.
- **FR-006**: El sistema MUST permitir que el cliente tenga una o varias **tiendas** (filas
  de `[Empresas]`), cada una vinculable a uno o varios almacenes.
- **FR-007**: El sistema MUST permitir crear y editar roles libremente, definiendo permisos
  de ver, crear, editar y eliminar/dar de baja por módulo del sistema.
- **FR-008**: El sistema MUST asignar exactamente un rol activo a cada usuario interno y
  aplicar sus permisos en todas las pantallas del módulo de Mantenimiento y en el acceso
  a acciones de cada entidad.
- **FR-009**: El sistema MUST permitir registrar trabajadores con o sin usuario de acceso
  asociado.
- **FR-010**: El sistema MUST permitir asociar puestos de trabajo a un trabajador y/o a un
  usuario.
- **FR-011**: El sistema MUST capturar para Artículos, como mínimo: código, descripción,
  familia, precio de venta, impuesto aplicable, proveedor habitual y control de stock por
  almacén.
- **FR-012**: El sistema MUST capturar datos fiscales y de contacto para Clientes y
  Proveedores.
- **FR-013**: El sistema MUST permitir vincular almacenes a una o varias tiendas.
- **FR-014**: El sistema MUST validar unicidad de identificadores clave de negocio (código
  de tienda, NIF del cliente en fila central, código de artículo, etc.) dentro del ámbito
  de la base de datos del cliente.
- **FR-015**: El sistema MUST restringir la edición de datos fiscales críticos del cliente
  (fila central) con facturas emitidas de forma que no se rompa la trazabilidad exigida por
  Veri*Factu o TicketBAI.
- **FR-016**: El sistema MUST presentar listados con búsqueda por texto en campos principales
  y filtros básicos al menos por estado (activo/inactivo) y, cuando aplique, por **tienda**
  (código de sucursal; campo legacy `Empresa` en otras tablas).
- **FR-017**: El sistema MUST mostrar mensajes de error y validación en español,
  comprensibles para usuarios administrativos no técnicos.
- **FR-018**: El módulo de Mantenimiento MUST integrarse con el sistema de permisos desde
  su diseño; no se admiten excepciones hardcodeadas por entidad fuera del modelo de roles.

### Key Entities

- **Empresa (cliente)**: Cliente propietario de la base de datos (Principio III). Sin tabla
  propia; datos fiscales en la fila `[Empresas]` con `Central = 1`. No admite alta ni baja.
- **Tienda**: Local/sucursal física; cada fila de la tabla legacy `[Empresas]`. El campo
  `Empresa` en otras tablas referencia el código de tienda. Vinculable a uno o varios almacenes.
- **Usuario**: Persona con acceso al sistema; tiene un rol asignado y credenciales de
  autenticación.
- **Rol**: Conjunto configurable de permisos (ver/crear/editar/eliminar) por módulo;
  no predefinido en el producto.
- **Trabajador**: Personal de la empresa; puede existir sin usuario de sistema.
- **Puesto de trabajo**: Posición operativa (caja, almacén, etc.) asociable a trabajador
  y/o usuario.
- **Artículo**: Producto comprable/vendible con atributos comerciales, impuesto y control
  de stock por almacén.
- **Cliente**: Tercero comprador con datos fiscales y de contacto.
- **Proveedor**: Tercero suministrador con datos fiscales y de contacto.
- **Almacén**: Ubicación física de stock; puede servir a una o varias tiendas.
- **Impuesto**: Tipo impositivo aplicable a artículos (IVA general, reducido, etc.).
- **Forma de pago**: Método de cobro/pago admitido (efectivo, tarjeta, transferencia, etc.).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Un administrador puede completar en una sola sesión la configuración fiscal del
  cliente, el alta de una tienda, un rol con permisos limitados a un solo módulo y un
  usuario con ese rol, en menos de 30 minutos sin asistencia de soporte técnico.
- **SC-002**: En pruebas con al menos 3 roles distintos, el 100% de intentos de edición en
  módulos no autorizados son denegados de forma visible para el usuario.
- **SC-003**: En cada entorno de prueba (BD cliente), el 100% de configuraciones con régimen
  común o País Vasco capturan todos los datos fiscales mínimos requeridos para emitir
  facturas conforme a Veri*Factu o TicketBAI respectivamente, según checklist fiscal.
- **SC-004**: El 100% de intentos de baja lógica sobre registros con dependencias activas
  son bloqueados con mensaje que identifica el tipo de dependencia.
- **SC-005**: Un usuario administrativo puede localizar cualquier registro maestro activo
  mediante búsqueda por código o nombre en menos de 3 acciones desde el listado de la
  entidad correspondiente.
- **SC-006**: Al menos el 90% de usuarios administrativos de prueba completan correctamente
  alta y edición de un artículo, cliente y proveedor en su primer intento sin errores de
  validación evitables.

## Assumptions

- Los usuarios del módulo son personal administrativo con formación básica en ERP; no se
  requiere formación técnica para operaciones CRUD estándar.
- La autenticación de usuarios existe o se implementará como prerequisito mínimo; este spec
  define autorización (roles/permisos), no el mecanismo de login.
- "Baja lógica" significa marcar el registro como inactivo sin borrado físico; los registros
  inactivos no aparecen en selecciones operativas por defecto pero permanecen auditables.
- La rehabilitación de registros inactivos está permitida para administradores con permiso
  de edición, salvo restricciones de integridad fiscal.
- Las entidades del mantenimiento se mapean al modelo de datos heredado de Descartes 1.0 sin
  renombrar tablas ni campos; ver `data-model.md` en el plan de implementación.
- **Tiendas** mapean directamente a la tabla legacy `[Empresas]` (cada fila = sucursal).
  **Empresa (cliente)** no tiene tabla; datos fiscales en la fila con `Central = 1`.
- Los módulos del sistema para asignación de permisos incluyen al menos: Mantenimiento,
  Compras, Ventas, Facturación, Inventario y TPV; nuevos módulos se añadirán al catálogo
  de permisos cuando se especifiquen.
- Cada **empresa cliente** posee su propia base de datos SQL Server (Principio III). Dentro
  de esa BD, el código de **tienda** (`Empresas.Codigo`) particiona operaciones; el campo
  legacy `Empresa` en otras tablas significa tienda, no empresa cliente.
- Fuera de alcance en esta versión: mantenimiento de módulos hoteleros (Habitaciones,
  Reservas, TarjetasHotel, MiniBar), importación masiva de datos, historial de auditoría
  detallado campo a campo y personalización avanzada de formularios por usuario.
