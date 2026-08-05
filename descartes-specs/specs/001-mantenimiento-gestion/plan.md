# Implementation Plan: Módulo de Mantenimiento (Gestión)

**Branch**: `001-mantenimiento-gestion` | **Date**: 2026-07-09 | **Spec**: [spec.md](./spec.md)  
**Input**: Feature specification from `/specs/001-mantenimiento-gestion/spec.md`

## Summary

Implementar el primer módulo de Descartes 2.0 Gestión: mantenimiento CRUD de 11 entidades
maestras (empresas, tiendas, usuarios, roles, trabajadores, puestos, artículos, clientes,
proveedores, almacenes, impuestos, formas de pago) con baja lógica, permisos configurables
por rol y mapeo al esquema SQL Server legacy (`db/script.sql`).

**Enfoque técnico**: API REST PHP/Slim (`descartes-api`) como único acceso a datos; SPA Vue 3
(`descartes-gestion`) con componente CRUD reutilizable; sesión PHP para autenticación;
tiendas mapeadas a tabla legacy `[Empresas]`; extensiones mínimas solo para Roles documentadas
en `data-model.md`.

## Technical Context

**Language/Version**: PHP **8.0+** (API), Vue 3.4+ / TypeScript 5+ (Gestión)  
**Primary Dependencies**: Slim 4, slim/psr7, php-di/php-di, sqlsrv/PDO; Vue 3, Vite 5, Vue Router, Pinia, axios  
**Storage**: SQL Server local — BD `larasa`; extensiones en `db/migrations/001-mantenimiento-extensiones.sql`  
**Testing**: PHPUnit 9.x (API), Vitest + Vue Test Utils (Gestión)  
**Target Platform**: XAMPP (Apache + **PHP 8.0.23**) en Windows; navegador Chromium/Edge para Gestión  
**Project Type**: API + SPA web (`descartes-api` + `descartes-gestion`)  
**Performance Goals**: Listados < 2 s con hasta 5 000 registros por entidad; formularios responsivos en LAN  
**Constraints**: Sin acceso directo a BD desde frontend; sin tablas nuevas salvo Roles/RolPermisos justificadas; tiendas = `[Empresas]` legacy; mensajes en español  
**Scale/Scope**: 11 entidades × 4 operaciones CRUD + matriz permisos; 1 BD por empresa cliente (preparado, 1 BD en dev)

### Ajuste de versión PHP (8.1+ → 8.0+)

El plan original especificaba PHP 8.1+. Tras el setup de Phase 1 (`/speckit.implement`), el requisito se ha
ajustado a **PHP 8.0+** porque es la versión instalada en el XAMPP local de desarrollo (8.0.23). Reflejado en
`descartes-api/composer.json` (`"php": "^8.0"`) y en PHPUnit 9.x (compatible con 8.0).

**Motivo**: Principio IX (local primero) — el entorno de desarrollo real del equipo es XAMPP con PHP 8.0; forzar
8.1+ bloquearía `composer install` sin actualizar el stack local.

**Sintaxis permitida en 8.0** (ya usada o prevista en la API):

| Característica | Desde | Uso en `descartes-api` |
|----------------|-------|-------------------------|
| `match` | 8.0 | `PermissionService`, `DependencyCheckService` |
| `str_starts_with` / `str_contains` | 8.0 | `AuthController`, dependencias Slim/php-di |
| Promoción de propiedades en constructor | 8.0 | Inyección de dependencias |
| Tipos de unión (`string\|null`) | 8.0 | Tipado de retorno y parámetros |
| Operador nullsafe (`?->`) | 8.0 | Permitido |
| Atributos (`#[...]`) | 8.0 | Permitido (no usado aún en código propio) |

**No usar** (requieren PHP ≥ 8.1):

| Característica | Versión mínima | Alternativa en 8.0 |
|----------------|----------------|---------------------|
| Propiedades `readonly` | 8.1 | Propiedades `private` + asignación solo en constructor |
| Clases `readonly` | 8.2 | Clases inmutables con propiedades privadas |
| `enum` nativos | 8.1 | Constantes de clase o strings tipados en docblock |
| Tipos de intersección (`Foo&Bar`) | 8.1 | Una sola interfaz o comprobación en runtime |
| Tipo `never` como retorno | 8.1 | `void` + `throw` o documentación |
| `array_is_list()` | 8.1 | Bucle o `array_keys` === `range(0, n-1)` |
| Fibers | 8.1 | No necesarios (modelo request/response síncrono Slim) |

**Deuda técnica (Phase 2, resuelta)**: el código inicial usaba `private readonly` (PHP 8.1+); refactorizado
a propiedades privadas clásicas en Phase 3 para compatibilidad con PHP 8.0.

**Producción futura**: cuando el XAMPP o el servidor de producción disponga de PHP 8.1+, se puede elevar el
requisito de `composer.json` y reintroducir `readonly` si se desea; no es obligatorio para el funcionamiento.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principio | Estado | Evidencia / notas |
|-----------|--------|-------------------|
| I. Programas independientes | ✅ PASS | Solo `descartes-gestion` + `descartes-api`; sin TPV ni acceso directo a SQL desde Vue |
| II. TPV offline-first | ✅ N/A | Fuera de alcance explícito en spec y plan |
| III. Aislamiento por empresa | ✅ PASS | Una BD por cliente vía `ClienteDbMiddleware` + `clients.php`; v1 dev resuelve siempre a `larasa` del `.env`; dentro de la BD, `Empresas.Codigo` particiona por tienda |
| IV. Modelo heredado | ✅ PASS con justificación | Tiendas = `[Empresas]` sin tabla nueva; solo `Roles`/`RolPermisos` + columnas `Baja` documentadas en `data-model.md` |
| V. Cumplimiento fiscal | ✅ PASS | Datos fiscales cliente en fila `Empresas` con `Central=1`; campos `TicketSI_*` legacy; bloqueo con facturas emitidas |
| VI. Roles configurables | ✅ PASS | Tablas `Roles`/`RolPermisos`; UI y API aplican permisos por módulo/acción |
| VII. Backend ligero | ✅ PASS | Slim + rutas explícitas por entidad; sin ORM pesado (queries parametrizadas) |
| VIII. Frontend simple | ✅ PASS | Un `MantenimientoCrud.vue` parametrizable; Pinia para sesión/permisos |
| IX. Local primero | ✅ PASS | XAMPP + SQL Server local; conexión vía `.env` |

**Post-design re-check (Phase 1)**: Todos los gates siguen en PASS. Sin violaciones que requieran Complexity Tracking.

## Project Structure

### Documentation (this feature)

```text
specs/001-mantenimiento-gestion/
├── plan.md              # Este archivo
├── research.md          # Decisiones (auth, tiendas, roles)
├── data-model.md        # Mapeo legacy + extensiones
├── quickstart.md        # Arranque local
├── contracts/
│   └── mantenimiento-api.openapi.yaml
└── tasks.md             # 77 tareas T001–T077 (post-remediación analyze)
```

### Source Code (repository root)

```text
c:\descartes-2.0\
├── db\
│   ├── script.sql
│   └── migrations\
│       └── 001-mantenimiento-extensiones.sql
│
├── descartes-api\
│   ├── public\
│   │   └── index.php
│   ├── src\
│   │   ├── Middleware\
│   │   │   ├── ClienteDbMiddleware.php   # selección DSN por cliente (Principio III)
│   │   │   ├── SessionMiddleware.php
│   │   │   ├── AuthMiddleware.php
│   │   │   ├── PermissionMiddleware.php
│   │   │   └── CorsMiddleware.php
│   │   ├── Http/
│   │   │   ├── ErrorResponse.php
│   │   │   └── ApiErrorHandler.php
│   │   ├── Routes\
│   │   │   ├── auth.php
│   │   │   └── mantenimiento.php
│   │   ├── Controllers\
│   │   │   ├── AuthController.php
│   │   │   └── MantenimientoController.php
│   │   ├── Services\
│   │   │   ├── MantenimientoService.php      # CRUD generico
│   │   │   ├── PermissionService.php
│   │   │   └── DependencyCheckService.php    # bloqueo baja logica
│   │   ├── Repositories\                     # una por entidad o generico + config
│   │   └── Config\
│   │       ├── database.php                  # factory PDO/sqlsrv
│   │       ├── clients.php                   # mapa clienteId → DSN (v1: siempre larasa)
│   │       └── entities.php                  # mapeo entidad → tabla/campos
│   ├── tests\
│   ├── composer.json
│   └── .env.example
│
└── descartes-gestion\
    ├── src\
    │   ├── components\
    │   │   ├── mantenimiento\
    │   │   │   ├── MantenimientoCrud.vue
    │   │   │   ├── MantenimientoTable.vue
    │   │   │   ├── MantenimientoForm.vue
    │   │   │   ├── RolPermisosMatrix.vue
    │   │   │   ├── ArticuloPreciosTab.vue
    │   │   │   └── ArticuloStockTab.vue
    │   │   └── layout\
    │   ├── views\
    │   │   ├── LoginView.vue
    │   │   └── mantenimiento\
    │   │       └── EntidadView.vue           # router param :entidad
    │   ├── composables\
    │   │   ├── useMantenimiento.ts
    │   │   └── usePermisos.ts
    │   ├── stores\
    │   │   └── auth.ts
    │   ├── api\
    │   │   └── client.ts                     # axios + withCredentials
    │   └── config\
    │       └── entidades.ts                    # columnas/form por entidad
    ├── tests\
    ├── package.json
    └── vite.config.ts
```

**Structure Decision**: Monorepo en `c:\descartes-2.0` con API y Gestión separados (Principio I).
Specs viven en `descartes-specs/`. El frontend nunca importa código PHP ni conecta a SQL Server.

## Phase 0: Research (complete)

Ver [research.md](./research.md). Decisiones clave:

1. **Auth**: sesión PHP nativa (no JWT en v1).
2. **Tiendas**: mapeo directo a `[Empresas]` legacy (cada fila = sucursal); sin tabla `[Tiendas]`.
3. **Empresa cliente**: sin tabla; singleton fiscal en fila `Central=1` de `[Empresas]`.
4. **Roles**: tablas `[Roles]` + `[RolPermisos]` — `Permisos` legacy insuficiente.
5. **Baja lógica**: campo por entidad según `data-model.md`.
6. **UI**: componente CRUD único parametrizado.
7. **Multi-BD (Principio III)**: `ClienteDbMiddleware` + `Config/clients.php` seleccionan la
   conexión PDO por cliente. En desarrollo v1 solo existe BD `larasa` local; el middleware
   implementa la interfaz completa pero resuelve siempre al DSN del `.env` hasta producción
   multi-cliente.

## Phase 1: Design (complete)

### Data model

Ver [data-model.md](./data-model.md) — mapeo completo de las 11 entidades, reglas de baja
lógica y script de migración propuesto.

### API contracts

Ver [contracts/mantenimiento-api.openapi.yaml](./contracts/mantenimiento-api.openapi.yaml).

**Patrón REST unificado** por entidad:

| Método | Ruta | Permiso |
|--------|------|---------|
| GET | `/api/mantenimiento/{entidad}` | ver |
| GET | `/api/mantenimiento/{entidad}/{codigo}` | ver |
| POST | `/api/mantenimiento/{entidad}` | crear |
| PUT | `/api/mantenimiento/{entidad}/{codigo}` | editar |
| DELETE | `/api/mantenimiento/{entidad}/{codigo}` | eliminar |

**Excepción**: `PUT /api/mantenimiento/roles/{codigo}/permisos` para matriz de permisos.

**Entidades** (path param CRUD): `tiendas` (tabla `[Empresas]`), `usuarios`, `roles`,
`trabajadores`, `puestos-trabajo`, `articulos`, `clientes`, `proveedores`, `almacenes`,
`impuestos`, `formas-pago`.

**Singleton** (sin listado/alta/baja): `GET`/`PUT` `/api/mantenimiento/empresas` → fila
`[Empresas]` con `Central = 1` (datos fiscales del cliente).

**Respuestas error estándar** (español):

```json
{
  "error": "No se puede dar de baja el articulo porque tiene ventas asociadas",
  "codigo": "DEPENDENCIA_ACTIVA",
  "dependencias": ["AlbaranesVentasLin"]
}
```

### Frontend design

- **Router**: `/mantenimiento/:entidad` → `EntidadView.vue` carga config de `entidades.ts`.
- **Permisos UI**: `usePermisos().puede('articulos', 'editar')` oculta/deshabilita acciones.
- **Formularios especiales**:
  - `empresas`: pantalla singleton — datos fiscales del cliente (fila central `Central=1`).
  - `tiendas`: CRUD sobre `[Empresas]`; marcar `esCentral`; no confundir con empresa cliente.
  - `roles`: subvista `RolPermisosMatrix.vue`.
  - `articulos`: pestaña precios (`ArtPrecios`) y stock por almacén (`Stock`).
  - `tiendas` / `almacenes`: selector vínculo tienda↔almacén (FR-013).

### Implementation phases (for /speckit.tasks)

| Fase | Entregable | User stories |
|------|------------|--------------|
| 0 | Migración SQL + esqueleto API/Gestión + auth | — |
| 1 | Empresa cliente (singleton) + Tiendas (`[Empresas]`) + Almacenes | US1, US3 |
| 2 | Roles + Usuarios + permisos end-to-end | US2 |
| 3 | Artículos + Clientes + Proveedores | US4 |
| 4 | Trabajadores + Puestos + Impuestos + Formas pago | US5 |
| 5 | Pulido: validaciones, mensajes, quickstart E2E | SC-001–006 |

## Complexity Tracking

> Sin violaciones de constitución que requieran justificación.

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| — | — | — |

## Out of Scope (this plan)

- TPV / Electron / SQLite sync
- Módulos Compras, Ventas, Facturación, Inventario (solo consumirán estos maestros después)
- Módulos hoteleros
- Importación masiva CSV
- Auditoría campo a campo

## Generated Artifacts

| Artifact | Path | Status |
|----------|------|--------|
| Research | [research.md](./research.md) | ✅ |
| Data model | [data-model.md](./data-model.md) | ✅ |
| API contract | [contracts/mantenimiento-api.openapi.yaml](./contracts/mantenimiento-api.openapi.yaml) | ✅ |
| Quickstart | [quickstart.md](./quickstart.md) | ✅ |
| Tasks | [tasks.md](./tasks.md) | ✅ (77/77 completadas) |
| API (`descartes-api`) | `c:\descartes-2.0\descartes-api\` | ✅ Phase 1–8 |
| Gestión (`descartes-gestion`) | `c:\descartes-2.0\descartes-gestion\` | ✅ Phase 1–8 |

## Next Step

**Feature 001-mantenimiento-gestion implementada.** Validar en entorno local con [quickstart.md](./quickstart.md) y `descartes-api/scripts/validate-phase8.ps1`. Siguiente feature según roadmap Descartes 2.0 (TPV, Compras, etc.).
