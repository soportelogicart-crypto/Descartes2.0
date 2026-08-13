# Descartes Electron (shell fino)

Electron carga la **interfaz Vue desde el servidor** y solo aporta lo local:
config del PC (INI) y perifericos (impresora, cajon, balanza, etc.).

```
Electron (caja)  -->  carga UI de appUrl (servidor / Vite)
                 -->  window.descartes.*  (puente nativo)
Servidor         -->  Vue + API PHP + SQL Server
```

## Requisitos

1. API en XAMPP (`descartes-api`)
2. Frontend Vue en marcha (`descartes-gestion`, puerto 5173 en desarrollo)
3. Node.js instalado

## Instalacion

```bash
cd descartes-electron
npm install
```

## Arranque (desarrollo)

Un solo comando: abre **solo la ventana Electron** (no el navegador).
Si hace falta, arranca Vite en segundo plano.

```bash
cd descartes-electron
npm run dev
```

Opcional — con DevTools de Electron:

```bash
npm run dev:tools
```

No hace falta abrir Chrome ni ejecutar `npm run dev` en `descartes-gestion` aparte.

## Configuracion

### URL de la UI (`config.json`)

```json
{
  "appUrl": "http://localhost:5173",
  "window": { "width": 1280, "height": 800, "maximized": true, "fullscreen": false }
}
```

En produccion, apuntar al build servido, por ejemplo:

`"appUrl": "http://servidor/descartes-gestion/"`

### Config del puesto (disco local)

Se guarda en:

`%APPDATA%/descartes-electron/config/equipo.json`

Contiene `equipoId` (hostname del PC), `empresaCodigo` y `puestoCodigo`.

- **No se borra** al limpiar cache del navegador.
- En Electron el identificador es el **hostname** automatico.
- Solo hace falta configurar empresa/puesto **una vez** (instalacion).

## Puente nativo (`window.descartes`)

| Metodo | Uso |
|--------|-----|
| `getEquipoConfig` / `setEquipoConfig` | Leer/guardar puesto del PC |
| `getHostname` | Nombre Windows del equipo |
| `listPrinters` | Impresoras del sistema (Windows/macOS) |
| `printTicket` | Ticket ESC/POS RAW (Windows) |
| `printHtml` | Documento A4 (HTML → cola Windows) |
| `printLabel` | Etiqueta HTML: `pageWidthMm`/`pageHeightMm` (+ `copies`) → misma ruta que `printHtml` |
| `openCashDrawer` | Cajon (stub) |
| `readScale` | Balanza (stub) |
| `displayPrice` | Visor cliente (stub) |

### Impresión de tickets

1. Arranque **Descartes Electron** (agente en `127.0.0.1:17321`).
2. En el puesto, configure **Tickets** (`ImpresoraTickets`) con el nombre (o parte) de la impresora Windows.
3. En Configuración → Tickets → plantilla **Ticket 80 mm** → **Probar ticket**.

El flujo es: Gestión/API → agente Electron → bytes ESC/POS → cola Windows (RAW).

Los stubs restantes (cajón, balanza, visor) estan en `electron/peripherals.js`.

### Impresión de etiquetas

1. Arranque **Descartes Electron**.
2. Puesto: **ImpresoraEtiquetas** + plantilla en Generales II (formato).
3. Bridge: `printLabel({ html, impresora, pageWidthMm, pageHeightMm, copies?, silent? })`.
   El tamaño debe coincidir con `plantilla.page.widthMm/heightMm`.

```js
await window.descartes.printLabel({
  html: '<html>…</html>',
  impresora: 'Zebra',
  pageWidthMm: 50,
  pageHeightMm: 30,
  copies: 2,
  silent: true,
})
```

## Actualizaciones

| Que cambia | Donde se despliega |
|------------|--------------------|
| Pantallas Vue, logica, API | **Solo servidor** |
| Drivers / puente / Electron | Instalador en cada caja (raro) |

## Seguridad

- `contextIsolation: true`, sin `nodeIntegration`
- La UI solo ve `window.descartes`, no Node completo
- El preload no debe ampliarse a APIs arbitrarias
