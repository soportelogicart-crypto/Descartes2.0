# Quickstart: 002-ventas-gestion

**Feature**: Módulo de Ventas (Gestión)  
**Branch**: `002-ventas-gestion`

## Prerequisites

- Stack local de `001-mantenimiento-gestion` operativo (XAMPP PHP 8.0+, SQL Server `larasa`, API + Gestión).
- Usuario con rol que incluya módulo **`ventas`** (`ver`; para pruebas de escritura también `crear`/`editar`).
- Datos de prueba generados desde TPV o insertados en tablas legado: al menos una sesión con `Arqueo`, ventas en `AlbaranesVentasCab`, filas en `LogAnulaciones` y `Vales` / `PedidosClientes` si se prueban esas pantallas.

## Repository layout

```text
c:\descartes-2.0\
??? db\script.sql
??? descartes-api\
??? descartes-gestion\
??? descartes-specs\specs\002-ventas-gestion\
```

## 1. Prerrequisito Formas de pago

Tras implementar la extensión del maestro:

```powershell
# Login
curl -X POST http://localhost/descartes-api/public/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"usuario":"ADM","password":"admin123"}' `
  -c cookies.txt

# Debe devolver cobroDeArqueo y cobroPago
curl http://localhost/descartes-api/public/api/mantenimiento/formas-pago -b cookies.txt
```

Validar en BD:

```sql
SELECT Codigo, CobroDeArqueo, CobroPago FROM FormasPago;
SELECT DISTINCT CobroPago FROM FormasPago;
SELECT DISTINCT TipoLiquidacion FROM Vales WHERE TipoLiquidacion IS NOT NULL;
```

## 2. API Ventas (tras implementar rutas)

```powershell
# Ventas del día
curl "http://localhost/descartes-api/public/api/ventas/albaranes?fechaDesde=2026-07-22&fechaHasta=2026-07-22" -b cookies.txt

# Arqueo
curl "http://localhost/descartes-api/public/api/ventas/arqueos?empresa=001&puesto=01&sesion=1" -b cookies.txt

# Desglose (equiv. registradora = filtro puesto)
curl "http://localhost/descartes-api/public/api/ventas/arqueos/desglose?empresa=001&puesto=01&sesion=1" -b cookies.txt

# Anulaciones
curl "http://localhost/descartes-api/public/api/ventas/anulaciones?fechaDesde=2026-07-22&fechaHasta=2026-07-22" -b cookies.txt

# Cobros/pagos
curl "http://localhost/descartes-api/public/api/ventas/cobros-pagos?tipo=cobro" -b cookies.txt

# Liquidar vale (debe fallar si caducado/liquidado)
curl -X POST "http://localhost/descartes-api/public/api/ventas/vales/001/V001/liquidar" `
  -H "Content-Type: application/json" -b cookies.txt `
  -d '{"fechaLiquidacion":"2026-07-22","tipoLiquidacion":"C"}'
```

## 3. Frontend

```powershell
cd c:\descartes-2.0\descartes-gestion
npm install
npm run dev
```

Abrir Gestión ? menú **Ventas**. Comprobar:

1. Listado de ventas del día filtrable en ?3 acciones (SC-001).
2. Arqueo cuadra con formas `CobroDeArqueo` (SC-002).
3. Vale caducado/liquidado no se reliquida (SC-003).
4. Diario de anulaciones usable sin SQL (SC-004).
5. Usuario solo-lectura: sin liquidar ni marcar Impreso (SC-005).

## 4. Sync XAMPP

Si Apache sirve `C:\xampp\htdocs\descartes-api\`, copiar/sincronizar cambios de `descartes-api` allí tras cada cambio de rutas/servicios.

## Out of scope (no probar aquí)

- Cobrar/crear ventas desde Gestión.
- Pantalla separada «Registradora».
- Alta de anulaciones desde Gestión.
- Facturación Veri*Factu/TicketBAI (solo consulta vínculo factura).
