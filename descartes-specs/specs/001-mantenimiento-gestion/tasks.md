---
description: "Task list for Módulo de Mantenimiento (Gestión)"
---

# Tasks: Módulo de Mantenimiento (Gestión)

**Input**: Design documents from `specs/001-mantenimiento-gestion/`  
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/mantenimiento-api.openapi.yaml

**Tests**: No solicitados en spec — tareas de test omitidas.

**Organization**: Por user story (US1–US5). Tiendas mapean a tabla legacy `[Empresas]`; empresa cliente = singleton `Central=1`. **77 tareas** (T001–T077).

### Cobertura explícita (remediación analyze)

| Hallazgo | Requisito | Tarea(s) |
|----------|-----------|----------|
| C3 | Principio III — middleware selección BD por cliente | **T012** `ClienteDbMiddleware` + `clients.php` (v1 → `larasa`) |
| C1 | FR-013 — vínculo tienda↔almacén | **T053** API + UI + convención en `data-model.md` |
| C2 | FR-011 — stock por almacén en artículos | **T063** `ArticuloStockRepository` + `ArticuloStockTab.vue` |

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Paralelizable (archivos distintos, sin dependencias pendientes)
- **[USn]**: User story del spec.md

## Path Conventions

- **API**: `descartes-api/src/`, `descartes-api/tests/`
- **Gestión**: `descartes-gestion/src/`
- **BD**: `db/migrations/`
- Raíz monorepo: `c:\descartes-2.0\`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Inicializar proyectos API y Gestión

- [X] T001 Crear estructura de carpetas `descartes-api/` según plan.md (`public/`, `src/Middleware`, `src/Routes`, `src/Controllers`, `src/Services`, `src/Config`, `tests/`)
- [X] T002 [P] Crear estructura de carpetas `descartes-gestion/` según plan.md (`src/components`, `src/views`, `src/composables`, `src/stores`, `src/api`, `src/config`)
- [X] T003 Inicializar `descartes-api/composer.json` con Slim 4, php-di/php-di, vlucas/phpdotenv y autoload PSR-4 `Descartes\Api\`
- [X] T004 [P] Inicializar `descartes-gestion/package.json` con Vue 3, Vite 5, Vue Router, Pinia, axios, TypeScript
- [X] T005 Crear `descartes-api/public/index.php` como front controller Slim
- [X] T006 [P] Crear `descartes-gestion/vite.config.ts` con proxy `/api` → XAMPP y alias `@/`
- [X] T007 [P] Crear `descartes-api/.env.example` con DB_SERVER, DB_NAME, DB_USER, DB_PASSWORD
- [X] T008 [P] Crear `descartes-gestion/.env.example` con VITE_API_BASE_URL

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Infraestructura que bloquea todas las user stories

**⚠️ CRITICAL**: Ninguna user story hasta completar esta fase

- [X] T009 Crear `db/migrations/001-mantenimiento-extensiones.sql` (Roles, RolPermisos, columnas Baja/Rol; **sin** tabla Tiendas) según data-model.md
- [X] T010 Crear `descartes-api/src/Config/entities.php` con mapeo entidad API → tabla SQL y campos (incl. `tiendas` → `Empresas`)
- [X] T011 Crear `descartes-api/src/Config/database.php` con factory PDO/sqlsrv y DSN desde `.env`
- [X] T012 Diseñar e implementar `descartes-api/src/Middleware/ClienteDbMiddleware.php` y `descartes-api/src/Config/clients.php` (mapa cliente→DSN; en v1 resolver siempre a BD `larasa` del `.env`, interfaz lista para multi-cliente en producción — Principio III)
- [X] T013 Implementar `descartes-api/src/Middleware/SessionMiddleware.php` (sesión PHP nativa)
- [X] T014 Implementar `descartes-api/src/Middleware/AuthMiddleware.php` (requiere usuario en sesión)
- [X] T015 Implementar `descartes-api/src/Middleware/PermissionMiddleware.php` (verifica modulo+accion via PermissionService)
- [X] T016 Implementar `descartes-api/src/Services/PermissionService.php` (lee RolPermisos por usuario)
- [X] T017 Implementar `descartes-api/src/Services/DependencyCheckService.php` (reglas baja lógica data-model.md)
- [X] T018 Implementar `descartes-api/src/Controllers/AuthController.php` (login, logout, me) en `src/Routes/auth.php`
- [X] T019 Implementar `descartes-api/src/Services/MantenimientoService.php` (CRUD genérico list/get/create/update/softDelete)
- [X] T020 Registrar rutas en `descartes-api/src/Routes/mantenimiento.php` y bootstrap en `public/index.php`
- [X] T021 [P] Crear `descartes-gestion/src/api/client.ts` con axios, `withCredentials: true` e interceptor 401
- [X] T022 [P] Crear `descartes-gestion/src/stores/auth.ts` (usuario, permisos, login/logout)
- [X] T023 [P] Crear `descartes-gestion/src/composables/usePermisos.ts` (`puede(modulo, accion)`)
- [X] T024 Crear `descartes-gestion/src/views/LoginView.vue` y ruta protegida en router
- [X] T025 [P] Crear componentes base `descartes-gestion/src/components/mantenimiento/MantenimientoTable.vue` y `MantenimientoForm.vue`
- [X] T026 Crear `descartes-gestion/src/components/mantenimiento/MantenimientoCrud.vue` (orquesta tabla+form+permisos UI)
- [X] T027 Crear `descartes-gestion/src/views/mantenimiento/EntidadView.vue` y ruta `/mantenimiento/:entidad`
- [X] T028 Crear layout de navegación Mantenimiento en `descartes-gestion/src/components/layout/` con menú de entidades

**Checkpoint**: Auth funcional + CRUD genérico listo para conectar entidades

---

## Phase 3: User Story 1 — Empresa cliente y datos fiscales (Priority: P1) 🎯 MVP

**Goal**: Pantalla singleton con datos fiscales del cliente (fila `[Empresas]` con `Central=1`)

**Independent Test**: GET/PUT `/api/mantenimiento/empresas` devuelve y actualiza NIF, régimen común/TicketBAI y contadores; UI edita sin soporte técnico

### Implementation for User Story 1

- [X] T029 [US1] Implementar `descartes-api/src/Repositories/EmpresaClienteRepository.php` (solo fila `Central=1`)
- [X] T030 [US1] Añadir rutas GET/PUT `/api/mantenimiento/empresas` en `descartes-api/src/Routes/mantenimiento.php` (no CRUD list/create/delete)
- [X] T031 [US1] Implementar validación fiscal en `descartes-api/src/Services/EmpresaClienteService.php` (NIF, TicketSI_*, bloqueo si facturas emitidas)
- [X] T032 [US1] Mapear campo derivado `regimenFiscal` (`comun`|`ticketbai`) desde `TicketSI_Territorio` en respuesta JSON
- [X] T033 [P] [US1] Crear `descartes-gestion/src/config/entidades.ts` entrada `empresas` (campos fiscales, sin listado)
- [X] T034 [US1] Crear `descartes-gestion/src/views/mantenimiento/EmpresaClienteView.vue` (formulario singleton, no MantenimientoCrud)
- [X] T035 [US1] Añadir ruta `/mantenimiento/empresas` y entrada en menú con permiso modulo `empresas`

**Checkpoint**: Datos fiscales del cliente editables (SC-003 parcial)

---

## Phase 4: User Story 2 — Usuarios, roles y permisos (Priority: P1)

**Goal**: Roles configurables con matriz ver/crear/editar/eliminar por módulo; usuarios con rol asignado

**Independent Test**: Rol solo-lectura en Artículos → usuario no puede PUT/DELETE en articulos; UI oculta botones (SC-002)

### Implementation for User Story 2

- [X] T036 [P] [US2] Implementar `descartes-api/src/Repositories/RolRepository.php` y `RolPermisoRepository.php`
- [X] T037 [US2] Extender `descartes-api/src/Routes/mantenimiento.php` con CRUD `roles` y PUT `roles/{codigo}/permisos`
- [X] T038 [US2] Implementar `descartes-api/src/Services/RolService.php` (integridad: no borrar rol con usuarios activos)
- [X] T039 [US2] Implementar CRUD `usuarios` en MantenimientoService con hash bcrypt de password y FK `Rol`
- [X] T040 [US2] Conectar PermissionMiddleware a todos los endpoints mantenimiento con modulo/accion correctos
- [X] T041 [P] [US2] Crear `descartes-gestion/src/components/mantenimiento/RolPermisosMatrix.vue` (grid módulos × acciones)
- [X] T042 [P] [US2] Añadir configs `roles` y `usuarios` en `descartes-gestion/src/config/entidades.ts`
- [X] T043 [US2] Integrar RolPermisosMatrix en EntidadView cuando `entidad === 'roles'`
- [X] T044 [US2] Aplicar `usePermisos()` en MantenimientoCrud para ocultar/deshabilitar crear/editar/eliminar por entidad
- [X] T045 [US2] Seed opcional: script SQL o comando para rol Administrador con todos los permisos en `db/migrations/001-mantenimiento-extensiones.sql`

**Checkpoint**: SC-001 pasos 4–6 y SC-002 verificables

---

## Phase 5: User Story 3 — Tiendas y almacenes (Priority: P2)

**Goal**: CRUD tiendas sobre `[Empresas]`; CRUD almacenes; baja lógica con dependencias

**Independent Test**: Alta tienda (nueva fila Empresas), alta almacén, listados con búsqueda; baja bloqueada con stock

### Implementation for User Story 3

- [X] T046 [US3] Configurar entidad `tiendas` en `descartes-api/src/Config/entities.php` (tabla `Empresas`, campos Central, FacturaLaCentral, Baja)
- [X] T047 [US3] Implementar validaciones tienda en MantenimientoService (no bajar única Central=1; bloqueo por Facturas/Albaranes con `Empresa=Codigo`)
- [X] T048 [P] [US3] Configurar entidad `almacenes` en `entities.php` (tabla `Almacenes`)
- [X] T049 [US3] Implementar reglas baja almacén en DependencyCheckService (Stock activo)
- [X] T050 [P] [US3] Añadir configs `tiendas` y `almacenes` en `descartes-gestion/src/config/entidades.ts`
- [X] T051 [US3] Añadir filtro `activo` y búsqueda `q` en listados API para tiendas y almacenes
- [X] T052 [US3] En formulario tiendas UI: etiquetar `esCentral` y advertencia al editar fila central
- [X] T053 [US3] Implementar vínculo tienda↔almacén (FR-013): documentar convención legacy en `data-model.md`, persistencia en API (`TiendaAlmacenService.php` o campo operativo) y selector en formulario tienda/almacén en `descartes-gestion`

**Checkpoint**: US3 acceptance scenarios 1–3 y FR-013 cumplidos

---

## Phase 6: User Story 4 — Artículos, clientes y proveedores (Priority: P2)

**Goal**: Catálogos comerciales con baja lógica y permisos por rol

**Independent Test**: Alta artículo con impuesto y proveedor; baja artículo con ventas → 409 DEPENDENCIA_ACTIVA

### Implementation for User Story 4

- [X] T054 [US4] Configurar `articulos` en `entities.php` (tabla `Articulos`; baja via `FechaBaja`)
- [X] T055 [US4] Implementar `descartes-api/src/Repositories/ArtPreciosRepository.php` para precios escalonados
- [X] T056 [US4] Extender endpoint articulos PUT/GET con sub-recurso o campos `precios` y `precioVenta` (PrecioVen1)
- [X] T057 [US4] Implementar DependencyCheckService para articulos (Stock, AlbaranesVentasLin)
- [X] T058 [P] [US4] Configurar `clientes` en `entities.php` (tabla `Clientes`; campo `Empresa` = tiendaCodigo en API)
- [X] T059 [P] [US4] Configurar `proveedores` en `entities.php` (tabla `Proveedores`; columna Baja)
- [X] T060 [P] [US4] Añadir configs articulos, clientes, proveedores en `descartes-gestion/src/config/entidades.ts`
- [X] T061 [US4] Extender MantenimientoForm para pestaña precios en articulos (`descartes-gestion/src/components/mantenimiento/ArticuloPreciosTab.vue`)
- [X] T062 [US4] Validar unicidad codigo articulo y NIF cliente en capa servicio
- [X] T063 [US4] Implementar control de stock por almacén en artículos (FR-011): `descartes-api/src/Repositories/ArticuloStockRepository.php` (tabla `Stock`) y pestaña `ArticuloStockTab.vue` en formulario artículo

**Checkpoint**: US4 acceptance scenarios y SC-006 parcial

---

## Phase 7: User Story 5 — Trabajadores, puestos, impuestos y formas de pago (Priority: P3)

**Goal**: Completar maestros auxiliares para TPV y transacciones futuras

**Independent Test**: Alta trabajador sin usuario, puesto caja asociado a usuario, impuesto y forma de pago usables en selects de US4

### Implementation for User Story 5

- [X] T064 [P] [US5] Configurar `trabajadores` en `entities.php` (tabla `Vendedores`)
- [X] T065 [P] [US5] Configurar `puestos-trabajo` en `entities.php` (tabla `Puestos`; columnas Trabajador, Usuario, Baja nuevas)
- [X] T066 [P] [US5] Configurar `impuestos` en `entities.php` (tabla `Impuestos`)
- [X] T067 [P] [US5] Configurar `formas-pago` en `entities.php` (tabla `FormasPago`)
- [X] T068 [US5] Implementar DependencyCheckService para puestos (Sesiones), impuestos (Articulos), formas-pago (Sesiones/cobros)
- [X] T069 [P] [US5] Añadir configs trabajadores, puestos-trabajo, impuestos, formas-pago en `entidades.ts`
- [X] T070 [US5] Formulario puestos: selectores trabajador y usuario opcionales en `MantenimientoForm.vue`
- [X] T071 [US5] Verificar selects en articulos cargan impuestos y proveedores activos desde API

**Checkpoint**: US5 acceptance scenarios 1–4 cumplidos

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Mensajes, rendimiento y validación E2E

- [X] T072 [P] Unificar respuestas error JSON en español (`error`, `codigo`, `dependencias`) en `descartes-api/src/Http/ErrorResponse.php`
- [X] T073 [P] Añadir paginación `page`/`pageSize`/`total` y búsqueda `q` en MantenimientoService listados (todas las entidades CRUD — FR-016, SC-005)
- [X] T074 Validar flujo completo SC-001 según `specs/001-mantenimiento-gestion/quickstart.md`
- [X] T075 Validar SC-002 (3 roles, 100% denegaciones) y SC-004 (bajas bloqueadas con mensaje)
- [X] T076 [P] Revisar que ningún endpoint Gestión accede a SQL Server (solo fetch a API)
- [X] T077 Actualizar `specs/001-mantenimiento-gestion/plan.md` estado de artefactos si procede

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1** → **Phase 2** → **Phases 3–7** (US1–US5) → **Phase 8**
- US2 (permisos) debería completarse antes de demo multi-rol; US1 puede avanzar en paralelo tras Phase 2

### User Story Dependencies

| Story | Depende de | Notas |
|-------|------------|-------|
| US1 | Phase 2 | Independiente; singleton fiscal |
| US2 | Phase 2 | Requerido para SC-002 antes de cerrar MVP completo |
| US3 | Phase 2 | Usa `[Empresas]`; independiente de US4/US5 |
| US4 | Phase 2; impuestos/proveedores útiles desde US5 para selects completos | Puede empezar con datos seed legacy |
| US5 | Phase 2 | Independiente; cierra catálogos auxiliares |

### Parallel Opportunities

- T002+T003, T006+T007+T008 (setup)
- T021–T023, T025 (frontend foundation)
- T036+T041+T042 (US2 API/UI paralelo)
- T048+T050 (US3 almacenes)
- T058–T060 (US4 configs)
- T064–T067+T069 (US5 configs)

---

## Parallel Example: User Story 2

```bash
# En paralelo tras T035:
T036 RolRepository + RolPermisoRepository
T041 RolPermisosMatrix.vue
T042 entidades.ts roles/usuarios
```

---

## Implementation Strategy

### MVP First (US1 + auth)

1. Phase 1 + Phase 2
2. Phase 3 (US1) — datos fiscales cliente
3. **VALIDAR** con quickstart pasos 1–2

### MVP administración completa (SC-001)

1. Añadir Phase 4 (US2) + Phase 5 (US3)
2. **VALIDAR** quickstart pasos 1–6 (< 30 min)

### Incremental

1. US1 → US2 → US3 → US4 → US5 → Polish
2. Cada checkpoint independiente según spec

---

## Notes

- **Tiendas = `[Empresas]`**: nunca crear tabla `Tiendas`
- **Empresa cliente**: solo GET/PUT `/api/mantenimiento/empresas` (Central=1)
- **Multi-BD (Principio III)**: `ClienteDbMiddleware` + `Config/clients.php` (T012); v1 dev → siempre `larasa`
- Campo legacy `Empresa` en otras tablas = código de **tienda**
- `[CentrosAdministrativos]`: fuera de alcance
- Commit tras cada fase o checkpoint
