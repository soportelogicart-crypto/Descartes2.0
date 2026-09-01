# Quickstart: 006-tpv-venta-tactil

**Feature**: Venta táctil (TPV)  
**Branch**: `006-tpv-venta-tactil`  
**Contrato**: [contracts/tpv-api.openapi.yaml](./contracts/tpv-api.openapi.yaml)

## Prerequisites

- Stack base: [001 quickstart](../001-mantenimiento-gestion/quickstart.md) (XAMPP + `larasa` + Vite).
- **Electron**: `descartes-electron` (`npm install`, `npm run dev`).
- Puesto en BD con `Teclado` > 0 y filas en `DefPlus` (p. ej. teclado `1` → `H_GENERAL='001'`).
- Migración permisos (cuando exista): `db/migrations/007-permisos-tpv.sql`.
- Login dev: `ADM` / `admin123`.

## Permisos

Módulo **`tpv`** en Roles:

| Acción | TPV |
|--------|-----|
| `ver` | Entrar en `/tpv`, ver sesión y ticket |
| `crear` | Vender, cobrar, escáner |
| `editar` | Cliente en curso, descuento, borrar línea |

## Comprobar teclado en BD

```sql
-- Puesto → teclado
SELECT Puesto, Teclado, Tarifa, ImpresoraTickets FROM Puestos WHERE Puesto = '01';

-- Layout (teclado 1 → '001')
SELECT H_NIVEL, H_TECLA, H_ETIQUET1, RTRIM(H_VALOR1) AS Articulo
FROM DefPlus WHERE H_GENERAL = '001' ORDER BY H_NIVEL, H_TECLA;
```

## Arranque desarrollo (caja)

Terminal 1 — API (sync si hubo cambios PHP):

```powershell
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1
```

Terminal 2 — Vue (si Electron no lo arranca solo):

```powershell
cd c:\descartes-2.0\descartes-gestion
npm run dev
```

Terminal 3 — Electron (ventana TPV):

```powershell
cd c:\descartes-2.0\descartes-electron
# Opcional en config.json: "appUrl": "http://localhost:5173/tpv"
npm run dev
```

Configurar **empresa/tienda + puesto** en `%APPDATA%/descartes-electron/config/equipo.json`.

Para enviar tickets y documentos por email, configurar SMTP en
`descartes-api/.env`:

```dotenv
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=usuario
MAIL_PASSWORD=contraseña
MAIL_FROM_ADDRESS=ventas@example.com
MAIL_FROM_NAME=Descartes
```

## Probar API (cuando esté implementada)

```powershell
curl -X POST http://localhost/descartes-api/public/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"usuario":"ADM","password":"admin123"}' -c cookies.txt

curl http://localhost/descartes-api/public/api/tpv/ping -b cookies.txt

curl http://localhost/descartes-api/public/api/tpv/contexto -b cookies.txt

curl "http://localhost/descartes-api/public/api/tpv/teclados/001/niveles/000" -b cookies.txt
```

## Flujo manual esperado (post-implementación)

1. Abrir TPV → contexto carga sesión + teclado nivel `000`.
2. Pulsar botón artículo → línea en ticket lateral.
3. **Cobrar** → Ticket + efectivo → finalizar.
4. Elegir **Imprimir**, **Enviar por email** o **No imprimir**.
5. **Gestión → Ventas** → filtrar hoy + puesto → ver venta.

## Offline (fase 4)

1. Completar venta con red activa al menos una vez (cache teclado).
2. Cortar red / detener Apache.
3. Vender y cobrar → ticket local + cola `pending`.
4. Restaurar red → sync automático → venta en Gestión.

## Referencias

- Arqueo / sesión: [003-arqueo-operativo/design.md](../003-arqueo-operativo/design.md)
- Ventas administrativas: [002-ventas-gestion](../002-ventas-gestion/spec.md)
- Electron: [descartes-electron/README.md](../../../descartes-electron/README.md)
