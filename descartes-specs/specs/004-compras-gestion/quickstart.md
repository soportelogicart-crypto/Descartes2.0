# Quickstart: 004-compras-gestion

**Feature**: Módulo de Compras (Gestión)  
**Branch**: `004-compras-gestion`  
**Contrato**: [contracts/compras-api.openapi.yaml](./contracts/compras-api.openapi.yaml)

## Prerequisites

- Stack ya levantado como en [001 quickstart](../001-mantenimiento-gestion/quickstart.md) (XAMPP + SQL `larasa` + Vite).
- Tras cambios en API: sync a XAMPP (`descartes-api/scripts/sync-xampp.ps1`) — ver [SYNC-XAMPP.md](../../../descartes-api/SYNC-XAMPP.md).
- Login dev: `ADM` / `admin123`.
- Migración permisos (si el rol no ve Compras): `db/migrations/006-permisos-compras.sql`.

## Permisos

Módulo lógico **`compras`** (Roles → permisos):

| Acción | UI / API |
|--------|----------|
| `ver` | Menú Compras, listados y detalle (albaranes, pedidos, facturas) |
| `crear` | Alta albarán / pedido |
| `editar` | Guardar cambios, **Actualizar stock**, **Recibir** pedido |
| `eliminar` | Borrar albarán solo si `Actualizado=0` y `TrasCtb=0` |

Sin `compras.ver` → rutas `/compras/*` denegadas. Facturas en v1 son solo lectura (`ver`).

## Contadores (por tienda)

En `[Empresas]` (ficha Tienda → Contadores):

| Documento | SQL | API / UI |
|-----------|-----|----------|
| Pedido a proveedor | `UltPedidoCom` | `ultPedidoCom` |
| Albarán de compra | `UltAlbaranCom` | `ultAlbaranCom` |

Al crear, la API asigna `contador + 1` y lo persiste en la misma transacción. `empresa` en documentos = código de **tienda**.

Comprobar antes/después de un alta:

```sql
SELECT Codigo, UltPedidoCom, UltAlbaranCom FROM Empresas WHERE Codigo = '1'
```

## Arranque rápido

```powershell
# API (si hubo cambios PHP)
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1

# Frontend
cd c:\descartes-2.0\descartes-gestion
npm run dev
```

Abrir `http://localhost:5173` → login → menú **Compras**.

Ping API (con cookie de sesión):

```powershell
curl -X POST http://localhost/descartes-api/public/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"usuario":"ADM","password":"admin123"}' -c cookies.txt

curl http://localhost/descartes-api/public/api/compras/ping -b cookies.txt
```

## Probar stock (SC-002)

1. Compras → **Albaranes** → **Nuevo**.
2. Cabecera: tienda (p. ej. `1`), proveedor activo, almacén, fecha.
3. Añadir ≥1 línea con artículo **no** bloqueado a compra; Guardar → número = `UltAlbaranCom` nuevo; `Actualizado=0`.
4. **Actualizar stock** → `Actualizado=1`; ficha pasa a solo lectura (no Guardar/Modificar cantidades).
5. Verificar en BD (ajustar artículo / almacén / mes):

```sql
-- Cabecera
SELECT Empresa, Albaran, Actualizado, TrasCtb, Almacen
FROM AlbaranesCompraCab WHERE Empresa = '1' AND Albaran = <n>

-- Stock del mes (Entradas)
SELECT Codigo, Almacen, Año, Mes, Entradas, ValorEntradas
FROM Stock
WHERE Codigo = '<articulo>' AND Almacen = <almacen>
ORDER BY Año DESC, Mes DESC
```

6. Segundo **Actualizar stock** del mismo albarán → **409** (`CONFLICTO`).
7. Borrar solo funciona si `Actualizado=0` y `TrasCtb=0`.

## Otros smoke útiles

| ID | Qué | Ruta UI |
|----|-----|---------|
| SC-001 | Listar / abrir albarán | `/compras/albaranes` |
| SC-003 | Pedido → Recibir parcial → albarán | `/compras/pedidos` |
| SC-005 | Imprimir A4 (puesto Generales II, no ticket) | Ficha albarán / pedido → Imprimir |

Impresión: plantillas/impresora del puesto (`formatoAlbaranCompras` / `formatoPedidoCompras`). Configure el puesto del equipo en Mantenimiento.

## Referencias

- [research.md](./research.md) — R-001 contadores, R-004 stock, R-008 permisos  
- [data-model.md](./data-model.md) — mapeo camelCase ↔ SQL  
- [tasks.md](./tasks.md) — checklist y smokes  
