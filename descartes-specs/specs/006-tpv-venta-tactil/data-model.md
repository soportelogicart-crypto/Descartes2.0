# Data Model: 006-tpv-venta-tactil

**Feature**: Venta táctil (TPV)  
**Date**: 2026-08-31  
**Source**: `db/script.sql` + [research.md](./research.md)

## Principio de mapeo

- API/UI: **camelCase** JSON.
- SQL Server: nombres legacy (ventas, teclado, sesión).
- `empresa` = **tienda** (`Empresas.Codigo`).
- Escritura de ventas cerradas: mismas tablas que Gestión → Ventas.

---

## 1. Teclado táctil (solo lectura API)

### Tablas

| Tabla | PK | Notas |
|-------|-----|-------|
| `Teclados` | `Codigo` (smallint) | Maestro; `Puestos.Teclado` FK lógica |
| `DefPlusC` | `H_GENERAL` | Nombre del layout |
| `DefPlus` | `(H_GENERAL, H_NIVEL, H_TECLA)` | Botones |

### Clave `H_GENERAL`

```
H_GENERAL = FORMAT(Puestos.Teclado, '000')  -- lógica; en SQL: RIGHT('000'+CAST(...),3)
```

### Botón → API `TpvBoton`

| API | SQL | Notas |
|-----|-----|-------|
| tecla | H_TECLA | int |
| nivel | H_NIVEL | string |
| etiqueta1 | H_ETIQUET1 | |
| etiqueta2 | H_ETIQUET2 | |
| etiqueta3 | H_ETIQUET3 | |
| ancho | H_ANCHO | span columnas |
| alto | H_ALTO | span filas |
| articulo | H_VALOR1 | trim; vacío = no venta directa |
| nivelDestino | H_NIVOP | navegación |
| nivelVolver | H_NIVOB | |
| color | H_COLOR | float legacy |
| icono | H_ICON | path opcional |
| tarifa | H_TARIFA | override |
| tipo | *(derivado)* | `articulo` \| `nivel` \| `vacio` |

### Nivel → API `TpvNivel`

| API | Contenido |
|-----|-----------|
| general | H_GENERAL |
| nivel | H_NIVEL |
| nombre | DefPlusC.H_NOMBRE |
| botones | TpvBoton[] |

---

## 2. Contexto de caja (API agregado)

`GET /api/tpv/contexto` (no tabla única):

| API | Origen |
|-----|--------|
| empresa | `Puestos` + `equipo.json` / config equipo API |
| puesto | `Puestos.Puesto` |
| sesion | `Sesiones` abierta (`Cerrada=0`) vía `ArqueoService` |
| tecladoCodigo | `Puestos.Teclado` |
| tecladoGeneral | padded `H_GENERAL` |
| tarifa | `Puestos.Tarifa` |
| impresoraTickets | `ImpresoraTickets` / plantilla puesto |
| vendedor | usuario → `Vendedores` si aplica |
| clienteDefecto | `ZZZZZZZZZ` venta rápida |

---

## 3. Ticket en curso (borrador)

### Online

- Cabecera/líneas en memoria Vue + opcional espejo servidor:
  - `POST /api/ventas` crea cabecera al confirmar cliente/tienda (patrón `VentaDetalleView`).
  - Líneas: `PUT /api/ventas/{empresa}/{tipo}/{albaran}`.

### Offline (SQLite en Electron)

| Tabla local | Campos clave |
|-------------|--------------|
| `tpv_draft` | `id`, `payload_json`, `updated_at` — una fila activa por puesto |
| `tpv_sync_queue` | `id`, `idempotency_key`, `payload_json`, `status`, `retries`, `last_error`, `created_at` |
| `tpv_offline_meta` | `key`, `value_json` — reserva numeración, cache teclado |

Estado sync: `pending` | `syncing` | `ok` | `error`.

---

## 4. Venta cerrada (SQL Server — reutilizado)

Igual que [002-ventas-gestion](../002-ventas-gestion/spec.md):

| Rol | Tabla | PK |
|-----|-------|-----|
| Cabecera | `AlbaranesVentasCab` | `(Empresa, Tipo, Albaran)` |
| Líneas | `AlbaranesVentasLin` | `(Empresa, Tipo, Albaran, NroLin)` |
| Sesión | `Sesiones` | `(Empresa, Puesto, Sesion)` |

Campos críticos al finalizar desde TPV:

| API / efecto | SQL |
|--------------|-----|
| sesion | Sesion |
| rebajeStock | RebajeStock = 1 |
| estado ticket | según `VentaEscrituraService::finalizar` |
| arqueo | `Add_Arq` vía `ArqueoService` |

---

## 5. Sync idempotente (servidor — opcional)

| API | SQL propuesto |
|-----|----------------|
| idempotencyKey | `TpvSyncLog.IdempotencyKey` (UUID) |
| empresa, albaran, tipo | columnas de log |

Sin fila en log → procesar venta; con fila → devolver referencia existente.

---

## 6. Reserva numeración offline

| API | Efecto |
|-----|--------|
| `POST /api/tpv/reservar-numeros` | Incrementa contador tienda (`UltTicket` / lógica ticket) y devuelve rango `{ desde, hasta }` para uso local |

Detalle contador exacto en implementación (alinear con `VentaEscrituraService` / `UltTicket` en `Empresas`).

---

## 7. Permisos

Módulo **`tpv`** en `RolPermisos`: `ver`, `crear`, `editar`, `eliminar` (eliminar reservado; anular línea = `editar`).

---

## State transitions (TPV)

```
[Inicio TPV] → contexto OK + sesión abierta
            → cargar nivel teclado '000'

[Borrador]  → líneas en memoria / tpv_draft (offline)
            → Cobrar → finalizar → venta cerrada + ticket
            → online: SQL inmediato
            → offline: sync_queue pending → ok

[Sync]      pending → syncing → ok | error (reintentos)
```
