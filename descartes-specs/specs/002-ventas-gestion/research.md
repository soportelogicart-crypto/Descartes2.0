# Research: 002-ventas-gestion

**Feature**: Módulo de Ventas (Gestión)  
**Date**: 2026-07-22  
**Spec**: [spec.md](./spec.md)

## R-001 — Alcance «Registradora» (FR-020)

- **Decision**: Unificar con Desglose de arqueo; filtro/selección por `Puesto`. Sin submenú ni entidad «registradora».
- **Rationale**: En `db/script.sql` no hay tabla ni concepto distinto de registradora; el arqueo ya es por `(Empresa, Puesto, Sesion)`. Evita UI duplicada (Principio VIII).
- **Alternatives considered**: Submenú atajo (mismo backend); pantalla con reglas distintas (sin evidencia en legado).

## R-002 — Diario de anulaciones (FR-021)

- **Decision**: Solo lectura sobre `LogAnulaciones` (origen TPV). Sin alta/edición de anulaciones ni motivos desde Gestión en este MVP.
- **Rationale**: Spec limita escritura a vales e impresión de pedidos; anular desde Gestión rompería integridad de ventas cerradas (FR-004).
- **Alternatives considered**: Completar motivo vacío; registrar anulaciones desde Gestión (aplazado).

## R-003 — Tabla de ventas

- **Decision**: Venta = fila de `AlbaranesVentasCab` + `AlbaranesVentasLin`. Discriminador `Tipo`. PK `(Empresa, Tipo, Albaran)`.
- **Rationale**: No existe tabla `Tickets`; el TPV ya escribe aquí. Factura vía `FacturaTipo`/`Factura`.
- **Alternatives considered**: Vista nueva solo-lectura (innecesaria); tablas `_R`/shards (fuera de alcance salvo necesidad).

## R-004 — Arqueo y denominaciones

- **Decision**: Leer `Arqueo` por sesión/puesto. Denominaciones = `Moneda01`…`Moneda20`. Totales filtrados por `FormasPago.CobroDeArqueo = 1`. Cabecera de sesión en `Sesiones`.
- **Rationale**: Modelo legado exacto; cumple «hasta 20 tipos» sin tabla de denominaciones.
- **Alternatives considered**: Recalcular solo desde albaranes (desvía del arqueo físico TPV); `ArqueoCamarero` (fuera de alcance MVP).

## R-005 — Cobros y pagos

- **Decision**: Vista derivada: expandir `Fpago1/2/3` + importes de cabecera (y slots adicionales si existen) cruzando `FormasPago.CobroPago`. Sin tablas `Cobros`/`Pagos`.
- **Rationale**: Spec y esquema: clasificación = atributo de forma de pago.
- **Alternatives considered**: Solo contadores de `Sesiones` (insuficiente para listado filtrable); tablas nuevas (rompe Principio IV).

## R-006 — Valores `CobroPago` / `TipoLiquidacion`

- **Decision**: API expone el código legado. Convención inicial: `C` = Cobro, `P` = Pago (validar con `SELECT DISTINCT CobroPago FROM FormasPago` en BD real). `TipoLiquidacion` = `nchar(1)`; UI con valores distintos observados, sin catálogo nuevo.
- **Rationale**: Sin documentación de códigos en el repo; mapeo por nombre de columna + muestreo.
- **Alternatives considered**: Inventar tabla de tipos (rechazado salvo justificación).

## R-007 — Forma de la API (no CRUD genérico)

- **Decision**: Rutas dedicadas `/api/ventas/...` (listados consulta + acciones vales/pedidos). Reutilizar auth, sesión, `PermissionMiddleware` con módulo `ventas`.
- **Rationale**: Ventas no es mantenimiento CRUD; PK compuesta; muchas lecturas filtradas; escrituras puntuales.
- **Alternatives considered**: Extender `MantenimientoController` (forzado); microservicio (rompe Principio I/VII).

## R-008 — Permisos

- **Decision**: Un módulo `ventas` (ya en `RolService::MODULOS`): `ver` = todos los submenús consulta; `crear` = alta pedidos (+ emisión vales si se trata como alta); `editar` = liquidar vales + marcar Impreso; `eliminar` no usado en MVP (o denegado).
- **Rationale**: Spec FR-016–019; módulo ya sembrado en migración 001.
- **Alternatives considered**: Un módulo por submenú (sobreingeniería para MVP).

## R-009 — Prerrequisito Formas de pago

- **Decision**: Extender mantenimiento `formas-pago` para exponer `cobroDeArqueo` (`CobroDeArqueo`) y `cobroPago` (`CobroPago`) en API/UI. Sin migración SQL.
- **Rationale**: Ventas consume esa config; hoy el entity registry solo mapea codigo/descripcion/activo.
- **Alternatives considered**: Hardcode en Ventas (duplica maestro).

## R-010 — Frontend Gestión

- **Decision**: Sustituir placeholder `/ventas` por layout con subrutas; reutilizar `EntidadGrid`/patrones de listado+detalle de mantenimiento donde encaje; vistas propias para arqueo/vales/pedidos.
- **Rationale**: Principio VIII; menú ya tiene sección `ventas`.
- **Alternatives considered**: Meter ventas dentro de `/mantenimiento` (confunde dominio).

## R-011 — Esquema / migraciones

- **Decision**: Sin cambios de esquema para MVP. Solo lectura + UPDATE acotado a `Vales` (liquidación) y `PedidosClientes` (Impreso / alta cab+lin).
- **Rationale**: Principio IV; tablas ya existen.
- **Alternatives considered**: Índices nuevos (solo si medición lo exige; documentar en follow-up).

## R-012 — Impresión de pedidos

- **Decision**: Acción API `POST/PATCH .../impreso` (o equivalente) que pone `Impreso = 1`. «Reimprimir» en Gestión = misma acción + disparo de impresión cliente si aplica; no entidad nueva.
- **Rationale**: Columna `PedidosClientes.Impreso` ya existe; análogo a ventas.
- **Alternatives considered**: Cola de impresión servidor (fuera de alcance Gestión web MVP).

## Clarificaciones cerradas sin `/speckit.clarify` completo

El usuario invocó `/speckit.plan` sin terminar el bucle de clarify. FR-020 y FR-021 se cerraron con las asunciones del spec (R-001, R-002). Riesgo: si negocio exige pantalla Registradora distinta o anotar anulaciones, habrá retrabajo de menú/API.
