# ABC Ventas — Electron + Node + SQL Server

App de escritorio independiente del monorepo Descartes. La UI es Vue; el backend corre en el proceso main de Electron (Node) y consulta **SQL Server** con `mssql`. No usa PHP.

## Estructura

```
abc-ventas-app/
  electron/                 Main process (Node)
    main.js
    preload.js
    db.js                   Pool SQL Server
    services/abcVentasService.js
  frontend/                 Vue 3 + Vite
  config.example.json       Plantilla de conexión
  config.json               Tu config local (no versionar)
  referencia/               Crystal / PDFs / MDB
```

## Configuración SQL Server

```bash
copy config.example.json config.json
```

Edita `config.json`:

```json
{
  "database": {
    "server": "TU-SERVIDOR",
    "database": "larasa",
    "user": "sa",
    "password": "****",
    "port": 1433,
    "options": {
      "encrypt": false,
      "trustServerCertificate": true
    }
  }
}
```

## Arranque

Desde la raíz de `abc-ventas-app`:

```bash
npm install
npm --prefix frontend install
npm run dev
```

Flujo: **Login** (tabla `Vendedores.Usuario` / `PassWord`) → **Home** (tile Listados Ventas) → informe ABC.

Menú nativo de Electron: **Salir** | **Configuración** (conexión SQL Server).

Eso abre Electron y, en desarrollo, arranca Vite en `http://127.0.0.1:5180`. La UI llama al informe por IPC (`window.abcVentas.obtenerAbc`), no por HTTP/PHP.

Con DevTools:

```bash
npm run dev:tools
```

Solo UI en el navegador (sin SQL; fallará al consultar):

```bash
npm run dev:ui
```

## API interna

| Canal IPC      | Descripción                          |
|----------------|--------------------------------------|
| `abc:obtener`  | Genera el listado ABC (filtros)      |
| `abc:testDb`   | Prueba `SELECT 1` contra SQL Server  |

La lógica del informe es el port a Node de `AbcVentasService` (dimensión vendedores, fórmulas Crystal de Dto/Importe/Coste/M.Agr.).
