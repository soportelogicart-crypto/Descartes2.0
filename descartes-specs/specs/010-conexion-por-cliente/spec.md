# Feature Specification: Conexión por cliente

**Feature Branch**: `010-conexion-por-cliente`  
**Created**: 2026-10-05  
**Status**: Draft  
**Input**: User description: "Dos PC en línea comparten la base de datos: si uno pasa de producción a demo, el otro cambia también. Se pensaba que la conexión se guardaba en cada PC, como el puesto. El día que haya más de un cliente con bases distintas, ¿qué pasa? Lo primero es un spec."

## Contexto

Hoy Gestión y el TPV no abren SQL Server. Hablan con una API y la API abre **una** base.

| Dónde | Qué se guarda | Alcance |
|-------|----------------|---------|
| `%APPDATA%/descartes-electron/config/equipo.json` | Empresa y puesto del PC | Solo ese PC |
| `descartes-api/var/instalacion.json` | Servidor SQL, nombre de base, usuario y contraseña | Toda la API |

El menú **Descartes → Conexión** escribe ese único `instalacion.json`. Dos programas que apuntan a la misma URL (el mismo hosting) leen y escriben el mismo fichero. Cambiar a demo en un PC cambia la base del otro.

La constitución (Principio III) pide lo contrario: cada cliente con su SQL Server, y la API elige la base en cada petición. El código ya reserva esa idea (`X-Cliente-Id`, `Config/clients.php`) pero la ignora y usa siempre `instalacion.json`.

En Descartes, **empresa** y **puesto** no son el cliente. Viven **dentro** de una base (`Empresas`, `Puestos`). No sirven para elegir la base: hasta que la API no se ha conectado, esos códigos no existen. Por eso el selector del Principio III, en este spec, es la **instalación** (el cliente), no la empresa del ticket.

## Decisión de producto

1. **La base es de la instalación, no del PC.** Dos cajas de la misma tienda comparten cliente y, por tanto, la misma base. Producción y demo son dos instalaciones, aunque las use la misma persona.
2. **El PC solo recuerda a qué instalación pertenece** (identificador + clave), igual que ya recuerda el puesto. No guarda el servidor SQL ni la contraseña.
3. **La API guarda la conexión de cada instalación** (servidor, base, usuario, contraseña). El menú Conexión modifica solo la instalación a la que ese PC está unido.
4. **La clave no viaja como contraseña de SQL.** Sirve para demostrar que el PC es de esa instalación. Sin ella, conocer el identificador no abre la base de otro.
5. **La API tiene que poder llegar al SQL** que figura en esa instalación. Una API en Internet no alcanza una IP `192.168.x.x` del local. Este spec no añade túnel ni agente. Si el SQL está solo en la red del cliente, la API de ese cliente se instala donde pueda verlo (local, VPN o SQL alcanzable).

**Fuera de alcance v1**

- Una sola base multi-tenant para varios clientes.
- Elegir la base según la empresa o el puesto de dentro de SQL.
- Mandar usuario y contraseña de SQL en cada petición desde Electron.
- Agente local, túnel o VPN automáticos.
- Varias bases a la vez en un mismo PC (un programa, una instalación).
- Panel de hosting para dar de alta clientes a distancia. El alta v1 es en la propia API (quien administra esa instalación).

---

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Dos instalaciones, dos bases (Priority: P1)

Quien instala Descartes da de alta dos instalaciones en la misma API (por ejemplo producción y demo). En cada una indica el servidor SQL, el nombre de la base y las credenciales. Un PC unido a producción sigue en su base cuando el otro PC, unido a demo, cambia o usa la demo.

**Why this priority**: Es el fallo actual y el caso del día en que haya más de un cliente.

**Independent Test**: Dos navegadores o dos PC contra la misma API, con dos instalaciones. Consultar un dato que solo existe en una base y ver que el otro PC no lo ve. Cambiar la base de la demo y comprobar que producción no se mueve.

**Acceptance Scenarios**:

1. **Given** la API sin instalaciones, **When** se crean «produccion» y «demo» con servidores o bases distintos, **Then** cada una tiene su propio identificador y su propia clave, y las credenciales SQL no se muestran otra vez en claro.
2. **Given** un PC unido a producción y otro a demo, **When** los dos consultan ventas o mantienen clientes, **Then** cada uno lee y escribe solo su base.
3. **Given** el PC de demo cambia el nombre de su base en Conexión, **When** el PC de producción sigue trabajando, **Then** producción permanece en su base anterior.
4. **Given** un PC presenta el identificador de producción sin la clave, **When** llama a la API, **Then** se rechaza y no se abre esa base.

---

### User Story 2 - Unir un PC a una instalación (Priority: P1)

En el primer arranque (o desde el menú Descartes) el PC pide el identificador y la clave que le han dado. Los guarda en el equipo, junto al puesto. A partir de ahí todas las peticiones van con esa instalación. Empresa y puesto se siguen eligiendo después, dentro de esa base, y siguen siendo de ese PC.

**Why this priority**: Sin el vínculo en el PC, la API no sabe qué fichero de conexión usar.

**Independent Test**: PC nuevo, introducir id y clave de una instalación ya creada, entrar, ver datos de esa base. Cerrar y abrir: no vuelve a pedirlos. Borrar el vínculo: vuelve a pedirlos y no arrastra la base anterior.

**Acceptance Scenarios**:

1. **Given** un PC sin instalación, **When** abre Descartes, **Then** no entra en ventas ni TPV hasta indicar identificador y clave válidos (salvo el alta de la primera instalación, historia 3).
2. **Given** identificador o clave incorrectos, **When** confirma, **Then** mensaje claro y no se guarda el vínculo.
3. **Given** el vínculo guardado, **When** se reabre el programa, **Then** no pide otra vez id ni clave y sigue en la misma base.
4. **Given** el PC ya unido, **When** se mira la configuración del puesto, **Then** empresa y puesto siguen siendo locales y no cambian la base.

---

### User Story 3 - La instalación que ya existe sigue funcionando (Priority: P1)

Quien hoy tiene un solo `instalacion.json` no se queda sin conexión al actualizar. Esa conexión pasa a ser la instalación por defecto, con su identificador, y los PC que aún no envían instalación siguen entrando ahí hasta que se les una una clave.

**Why this priority**: No se puede romper el puesto que ya está en producción el día del cambio.

**Independent Test**: API con el `instalacion.json` actual. Sin cabecera de cliente, la API abre esa misma base. Tras el alta, un PC nuevo puede unirse a otra instalación sin modificar la que ya había.

**Acceptance Scenarios**:

1. **Given** solo existe la conexión actual, **When** un programa antiguo llama sin identificador, **Then** la API usa esa conexión y no responde vacío ni error de «cliente desconocido».
2. **Given** se crea una segunda instalación, **When** el programa antiguo sigue sin identificador, **Then** no salta solo a la nueva.
3. **Given** la conexión por defecto, **When** se abre Conexión desde un PC todavía no unido, **Then** edita esa instalación por defecto, no una al azar.

---

### User Story 4 - Cambiar el SQL de una instalación (Priority: P2)

Desde Conexión, el PC unido prueba y guarda servidor, base, usuario y contraseña. Eso actualiza solo su instalación. Puede probar antes de guardar. Si la prueba falla, no sustituye la conexión que ya funcionaba.

**Why this priority**: Es la pantalla que hoy pisa a todo el mundo. Tiene que quedar limitada a una instalación.

**Independent Test**: PC de demo cambia la base, prueba, guarda. Producción intacta. Una prueba fallida deja la demo como estaba.

**Acceptance Scenarios**:

1. **Given** un PC unido, **When** abre Conexión, **Then** ve el servidor y la base de **su** instalación, no los de otra, y no ve la contraseña ya guardada.
2. **Given** pulsa Probar con datos válidos, **Then** la API confirma que llega a ese SQL sin guardar todavía.
3. **Given** Guardar con una prueba correcta, **Then** los demás PC de la misma instalación pasan a esa base en la siguiente petición, y los de otra instalación no.
4. **Given** la prueba falla, **When** cierra sin un guardado correcto, **Then** la conexión anterior sigue en uso.

---

### Edge Cases

- Identificador duplicado al crear: se rechaza; no se sobrescribe la instalación existente.
- Clave perdida: quien administra la API puede generar otra. La anterior deja de valer. Los PC tienen que introducir la nueva.
- La API no alcanza el SQL (IP local, SQL parado, firewall): Conexión muestra el fallo de red. El resto de instalaciones no se ve afectado.
- Sesión abierta y el PC cambia de instalación: la sesión anterior no sirve. Hay que volver a entrar. Una cookie de producción no autoriza peticiones de demo.
- Dos PC de la misma instalación: cambian la conexión los dos a la vez, porque comparten base. No es un error.
- Nombre de base vacío o servidor vacío: no se guarda.
- Credenciales SQL en disco: no en claro en el PC. En la API, no hace falta un cifrado distinto del que ya protege `instalacion.json` en v1; el fichero no se sirve por HTTP.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: La API MUST resolver la base en cada petición por instalación, no con un único `instalacion.json` compartido por todos los que llamen.
- **FR-002**: Cada instalación MUST tener identificador, clave secreta y conexión SQL propia (servidor, base, usuario, contraseña, opciones de cifrado ya usadas hoy).
- **FR-003**: El alta MUST generar la clave. MUST poder regenerarla invalidando la anterior. El identificador MUST ser único.
- **FR-004**: Electron MUST guardar en el PC solo identificador y clave, en la configuración local del equipo, y MUST enviarlos en las peticiones a la API.
- **FR-005**: La API MUST rechazar identificador sin clave válida. MUST NOT abrir la base de otra instalación.
- **FR-006**: Empresa y puesto MUST seguir guardados solo en el PC y MUST aplicarse después de elegida la base. Cambiar de puesto MUST NOT cambiar de base.
- **FR-007**: Conexión MUST leer y guardar únicamente la instalación unida a ese PC. Probar MUST NOT persistir. Un fallo de prueba MUST NOT borrar la conexión vigente.
- **FR-008**: Si no viene instalación, la API MUST seguir usando la conexión única actual (`instalacion.json` o equivalente por defecto) para no cortar a los programas ya instalados.
- **FR-009**: La sesión de usuario MUST quedar atada a la instalación. MUST NOT reutilizarse contra otra.
- **FR-010**: La contraseña SQL MUST NOT almacenarse en el PC ni MUST NOT viajar en cada petición de negocio. Solo en el alta o el cambio de Conexión, hacia la API, por el canal ya usado (HTTPS).
- **FR-011**: Este cambio MUST NOT alterar el esquema SQL de Descartes (Principio IV). La configuración de instalaciones vive en ficheros de la API, no en tablas de la base del cliente.
- **FR-012**: La API MUST dejar claro, si no puede conectar, que el fallo es de esa instalación (servidor inalcanzable, base inexistente o credencial), sin mezclar el error con el resto.

### Key Entities

- **Instalación**: cliente de Descartes (una tienda, una demo, un tercero). Identificador, clave, conexión SQL. Una base. Muchos PC pueden unirse a ella.
- **Vínculo del PC**: identificador + clave guardados en el equipo. No incluye servidor ni contraseña SQL. Convive con empresa y puesto.
- **Conexión SQL**: servidor (nombre o IP, con instancia si la hay), nombre de base, usuario, contraseña y flags de certificado. Pertenece a una instalación.
- **Instalación por defecto**: la conexión que hoy está en `instalacion.json`. Sigue atendiendo a quien no envía vínculo.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Con dos instalaciones configuradas, un cambio de base en una deja operando la otra sin reconectarla a mano.
- **SC-002**: Un PC nuevo queda unido y entra en su base en un solo paso (id + clave), sin copiar `instalacion.json` a ese PC.
- **SC-003**: Un programa que hoy funciona contra la API, sin identificador, sigue abriendo la misma base después de desplegar este cambio.
- **SC-004**: Intentar usar el identificador de otra instalación sin su clave no devuelve datos de esa base.
- **SC-005**: Empresa y puesto de un PC no se alteran al cambiar la instalación, y al revés: cambiar el puesto no mueve la base.

## Assumptions

- En v1 hay una sola API de proceso y varias conexiones, una por petición, según la instalación. No se despliega una carpeta de API distinta por cliente (eso sigue siendo válido como alternativa manual, pero este spec es para no depender de ello).
- El alta de la segunda instalación la hace quien ya administra la API (pantalla de instalación / conexión), no un cajero en el TPV.
- Producción y demo del mismo responsable son dos instalaciones. No se intenta meter las dos bases en un solo vínculo.
- El SQL de cada instalación es alcanzable desde la máquina donde corre esa API. Si más adelante la API está en Internet y el SQL en un local cerrado, hará falta otro spec (API en el local, VPN o agente).
- Usuarios, roles y permisos siguen siendo los de cada base. No hay un usuario «de hosting» dentro de las bases de los clientes.
- La clave de instalación es un secreto de equipo, como no lo es el código de puesto: quien la tiene puede apuntar un PC a esa base. Por eso se regenera si se pierde el control del PC.
