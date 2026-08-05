# Implementation Plan: [FEATURE]

**Branch**: `[###-feature-name]` | **Date**: [DATE] | **Spec**: [link]
**Input**: Feature specification from `/specs/[###-feature-name]/spec.md`

**Note**: This template is filled in by the `/speckit.plan` command. See `.specify/templates/plan-template.md` for the execution workflow.

## Summary

[Extract from feature spec: primary requirement + technical approach from research]

## Technical Context

<!--
  ACTION REQUIRED: Replace the content in this section with the technical details
  for the project. The structure here is presented in advisory capacity to guide
  the iteration process.
-->

**Language/Version**: [e.g., PHP 8.x + Slim 4 (API), Vue 3 + Vite (Gestión), Electron + Node (Venta) or NEEDS CLARIFICATION]  
**Primary Dependencies**: [e.g., Slim, Vue 3, better-sqlite3, ESC/POS driver or NEEDS CLARIFICATION]  
**Storage**: [e.g., SQL Server per empresa (API), SQLite local + sync queue (Venta) or NEEDS CLARIFICATION]  
**Testing**: [e.g., PHPUnit, Vitest, Playwright or NEEDS CLARIFICATION]  
**Target Platform**: [e.g., XAMPP local, Windows TPV, navegador web or NEEDS CLARIFICATION]
**Project Type**: [descartes-api | gestion | venta — ver constitution Principios I, VII, VIII]  
**Performance Goals**: [domain-specific; TPV offline-first MUST complete sale without sync or NEEDS CLARIFICATION]  
**Constraints**: [e.g., no acceso directo a BD, Veri*Factu/TicketBAI, permisos configurables or NEEDS CLARIFICATION]  
**Scale/Scope**: [e.g., múltiples empresas, varias tiendas por empresa or NEEDS CLARIFICATION]

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

[Gates determined based on constitution file]

## Project Structure

### Documentation (this feature)

```text
specs/[###-feature]/
├── plan.md              # This file (/speckit.plan command output)
├── research.md          # Phase 0 output (/speckit.plan command)
├── data-model.md        # Phase 1 output (/speckit.plan command)
├── quickstart.md        # Phase 1 output (/speckit.plan command)
├── contracts/           # Phase 1 output (/speckit.plan command)
└── tasks.md             # Phase 2 output (/speckit.tasks command - NOT created by /speckit.plan)
```

### Source Code (repository root)
<!--
  ACTION REQUIRED: Replace the placeholder tree below with the concrete layout
  for this feature. Delete unused options and expand the chosen structure with
  real paths (e.g., apps/admin, packages/something). The delivered plan must
  not include Option labels.
-->

```text
descartes-api/          # PHP/Slim — único punto de acceso a SQL Server
├── src/
│   ├── routes/
│   ├── services/
│   └── middleware/     # auth, empresa → conexión BD
└── tests/

gestion/                # Vue 3 + Vite — solo habla con descartes-api
├── src/
│   ├── components/     # tablas, formularios, listados reutilizables
│   ├── views/
│   └── services/
└── tests/

venta/                  # Electron — SQLite local + cola de sincronización
├── src/
│   ├── main/           # periféricos: ESC/POS, etiquetas, HID, báscula
│   ├── renderer/
│   └── sync/
└── tests/

db/
└── script.sql          # esquema heredado Descartes 1.0 (206 tablas)
```

**Structure Decision**: [Document the selected structure and reference the real
directories captured above]

## Complexity Tracking

> **Fill ONLY if Constitution Check has violations that must be justified**

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| [e.g., 4th project] | [current need] | [why 3 projects insufficient] |
| [e.g., Repository pattern] | [specific problem] | [why direct DB access insufficient] |
