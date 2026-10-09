# Datáfono integrado (Redsys / TpvpcImplantado)

Documento de funcionamiento e instalación de Descartes 2.0. El único driver real es **SERMEPA** (centro de comunicación 6, librería `dllTpvpcLatente` de Redsys).

La clave del comercio, el número de comercio y el puerto serie **no están en la base de datos**. Están en el PC de la caja. La API no abre el pinpad.

## Qué hay en cada capa

| Capa | Dónde | Qué hace |
|---|---|---|
| Pantalla | `descartes-gestion` | Decide cobrar o devolver y espera el resultado. No llama a la DLL. |
| Contrato | `descartes-gestion/src/composables/cobroDatafono.ts` | Arma la operación: importe en céntimos, `PAGO` o `DEVOLUCION`, identificador. |
| Caja | `descartes-electron` | Elige el driver, guarda la clave y lanza el pinpad. |
| Ayudante | `descartes-electron/electron/redsys/RedsysTpvpc.cs` | Proceso de 32 bits que habla con el COM de Redsys. |
| API | `DispositivoPuestoService` | Solo si la pantalla no es Electron: reenvía al agente local `127.0.0.1:17321`. |

En Descartes Electron el cobro **no pasa por la API**. Vue llama a Electron por IPC. La API es el camino de reserva cuando la pantalla se abre en un navegador y Electron está arrancado en ese mismo PC (el agente HTTP).

```
TPV (Vue)
  └─ cobroDatafono.ts
       ├─ Electron: IPC peripheral:paymentTerminalCharge
       └─ Navegador: POST /api/ventas/puestos/{puesto}/dispositivo/datafono/cobrar
                        └─ API → http://127.0.0.1:17321/datafono/cobrar
  payment-terminal.js          (registro de drivers)
       └─ sermepa-driver.js    (código SERMEPA)
            └─ RedsysTpvpc.exe  (compilado en el PC, plataforma x86)
                 └─ DllTpvpcLatente.TpvpImplantado
                      └─ pinpad por el puerto COM
```

## Por qué un ejecutable aparte

Electron es un proceso de 64 bits. La librería de Redsys es COM de **32 bits** y solo responde en un hilo STA que bombea mensajes de Windows (`Application.DoEvents`). Meter esa DLL dentro de Electron no funciona.

`sermepa-driver.js` compila `RedsysTpvpc.cs` la primera vez con el compilador que trae Windows:

`C:\Windows\Microsoft.NET\Framework\v4.0.30319\csc.exe`

Parámetros: `/platform:x86`, referencia a `System.Windows.Forms.dll`. El ejecutable queda en:

`%APPDATA%\descartes-electron\redsys\RedsysTpvpc.exe`

Se recompila solo si el `.cs` es más nuevo que el `.exe`. El fuente va desempaquetado del asar (`asarUnpack`: `electron/redsys/**`), porque `csc.exe` no lee dentro de `app.asar`.

El ayudante lee un JSON por la entrada estándar y escribe el resultado por la salida estándar. Electron no enlaza la DLL.

## Cobro

`OperPinPad` del objeto COM, después de `IniTpvpcLatente`.

| Parámetro | Valor |
|---|---|
| importe | Con dos decimales, por ejemplo `12.50` |
| moneda | `978` (euro) |
| tipo | `PAGO` |
| pedido | Últimos 12 dígitos de la referencia interna |

La respuesta es el XML de `ResultOper`. Se considera aprobada si el XML trae resultado autorizado o un código de respuesta entre 0 y 99, y no trae un error TPV/SOAP.

Modo demo (casilla en la configuración del PC): no llama al pinpad. Devuelve autorización `DEMO`.

Tiempo máximo de espera: 120 segundos (configurable, entre 5 y 300). Si se agota, el cobro se cancela y la venta no se cierra.

## Devolución

`OperPinPad` no sirve para devolver. Esas funciones no están en el COM; se llaman como exportaciones de `dllTpvpcLatente.dll`. Antes hay que inicializar con `fnDllIniTpvpcLatente(comercio, terminal, clave, puerto, version)`.

La función habitual, la misma que el TPV antiguo deja en `LOG_TAR`, es:

```text
fnDllOperComContable(pedido, rts, importe, factura, tipoOper, xml, tamMax)
```

Con tipo de operación `DEVOLUCION`. El modo del programa antiguo (hay identificador RTS y no hay pedido Redsys) rellena así:

| Parámetro | Valor |
|---|---|
| pedido | Identificador RTS del cobro original |
| rts | Vacío |
| importe | Importe a devolver, dos decimales |
| factura | Código de autorización del cobro original, o la referencia (máximo 40 caracteres) |
| tipoOper | `DEVOLUCION` |
| xml | Búfer de respuesta, 2048 bytes |

Otras vías, si el cobro original trae otros datos:

| Condición | Función |
|---|---|
| Hay pedido Redsys y se pide la tarjeta en el pinpad | `fnDllComContableTrj(importe, factura, pedido, rts, xml, tamMax)` |
| Hay pedido Redsys | `fnDllOperComContable(pedido, rts, importe, factura, "DEVOLUCION", xml, 2048)` |
| Sin cobro original | `fnDllDevSinOrigTrj(importe, factura, xml, tamMax)` |

Sin pedido y sin RTS, el ayudante no llama al pinpad: responde que falta el pedido del cobro original.

El puerto se normaliza al formato del TPV antiguo. Si en pantalla se escribe `COM9`, al pinpad se le manda `COM9:,19200,N,8,1`.

## Dónde se guarda la configuración

En el PC, dentro de `equipo.json`:

`%APPDATA%\descartes-electron\config\equipo.json`

La escribe **Configuración → Datáfono** (`DatafonoConfiguracionView.vue`). Hace falta haber guardado antes empresa y puesto de ese equipo. Campos que usa SERMEPA:

| Campo | Uso |
|---|---|
| `activo` | Si es falso, el TPV no cobra con el pinpad |
| `driver` | `SERMEPA` |
| `centro` | `6` = Redsys / Sermepa |
| `comercio` | Número de comercio |
| `terminal` | Terminal Redsys |
| `clave` | Clave del TPV. No se devuelve a la pantalla: la UI solo recibe `claveConfigurada: true` |
| `version` | Versión de la librería. El driver usa `8.1` si el campo va vacío; la pantalla propone `4.1` |
| `puerto` | `COM9` o la cadena completa `COMn:,19200,N,8,1` |
| `demo` | No llama al pinpad |
| `timeoutMs` | Espera del cobro |

En SQL solo hay dos datos, y no bastan para abrir el pinpad:

- La forma de pago lleva el indicador de datáfono (`FormasPago.Datafono`). Si no, el TPV cobra esa forma sin pinpad.
- El puesto guarda el código de driver (`Puestos.Datafono`), por ejemplo `SERMEPA`. Tiene que coincidir con el driver registrado en Electron.

La clave del fichero local gana siempre a la que pudiera venir en la petición. Así una página web no puede sustituirla.

## Instalación en un PC de caja

1. Instalar **TpvpcImplantado** de Redsys (32 bits) y dejar registrada la librería COM `DllTpvpcLatente.TpvpImplantado`. La DLL se busca en `TPVPCIMPLANTADO`, en `C:\Program Files (x86)\TpvpcImplantado` o en `C:\Program Files\TpvpcImplantado`.
2. Windows 10/11 ya trae .NET Framework 4 (`csc.exe`). Sin eso Electron no puede generar el ayudante.
3. Instalar Descartes Electron (`npm run dist` → `Descartes-2.0-Setup-….exe`). El pinpad no funciona abriendo solo la web en un navegador, salvo que Electron esté abierto en ese PC por el agente del puerto 17321.
4. En el programa: empresa y puesto de ese PC.
5. **Configuración → Datáfono**: activar, centro Sermepa (6), comercio, terminal, clave y puerto COM. Probar el estado. Tiene que inicializar el pinpad y, si responde, devolver el número de serie.
6. En el puesto, driver `SERMEPA`. En la forma de pago de tarjeta, marcar datáfono.
7. El primer cobro compila `RedsysTpvpc.exe`. Si falla, el mensaje sale en el TPV (falta .NET, no está el COM, puerto mal, comercio o clave incorrectos).

El agente local escucha solo en `127.0.0.1:17321`. Las rutas `/datafono/cobrar`, `/datafono/cancelar` y `/datafono/estado` rechazan cualquier petición que traiga cabecera `Origin`, para que un sitio web abierto en el navegador no pueda ordenar un cobro a localhost. Electron (IPC) y la API (el proxy no manda `Origin`) sí pueden.

## Añadir otro fabricante

En `payment-terminal.js` hay un registro. Hoy solo existe:

```js
registerDriver('SERMEPA', require('./sermepa-driver'))
```

Un driver nuevo exporta `charge(payload, signal)` y, si aplica, `status(payload)` y `cancel(payload)`. `charge` devuelve `{ ok, approved, authorization, reference, code, message, receipt }`. El código del `registerDriver` es el que se guarda en `Puestos.Datafono` y en `equipo.json` → `driver`.

No hace falta tocar el TPV: `cobroDatafono.ts` ya manda el mismo contrato. El driver nuevo sí tiene que vivir en el PC de la caja, igual que SERMEPA, porque el aparato no está en el servidor.

## Diagnóstico

Cada operación deja una copia sin la clave en:

`%APPDATA%\descartes-electron\redsys\ultima-operacion.json`

Ahí están el JSON enviado al ayudante y el XML devuelto. Sirve para ver si la llamada fue `OperPinPad` o `fnDllOperComContable`, y el código de rechazo de Redsys.
