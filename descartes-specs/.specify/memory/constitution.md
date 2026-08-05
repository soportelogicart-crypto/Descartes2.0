<!--
Sync Impact Report
==================
Version change: (deleted/recreated) → 1.0.0
Modified principles: N/A (initial ratification from user-provided document)
Added sections:
  - Principios (I–IX)
  - Restricciones adicionales
  - Gobernanza
Removed sections: None (replaces prior English draft)
Templates requiring updates:
  - .specify/templates/plan-template.md ✅ updated (Descartes 2.0 project structure + stack defaults)
  - .specify/templates/spec-template.md ✅ updated (fiscal/permissions constitution notes)
  - .specify/templates/tasks-template.md ✅ updated (API/Gestión/Venta paths + foundational tasks)
  - .specify/templates/commands/*.md ⚠ N/A (directory does not exist)
  - README.md / docs/quickstart.md ⚠ pending (not present yet)
Follow-up TODOs: None
-->

# Constitution — Descartes 2.0

## Principios

### I. Programas independientes, misma base de negocio

Gestión (web) y Venta/TPV (Electron) son aplicaciones independientes que NUNCA se comunican
directamente entre sí. Toda comunicación pasa por la API (`descartes-api`). Ningún programa
accede a la base de datos directamente sin pasar por la API.

### II. TPV offline-first (NO NEGOCIABLE)

El programa de Venta DEBE poder completar una venta completa (cobro, ticket, actualización de
stock local) sin conexión a internet. Los datos se guardan en una BD local (SQLite) y se
sincronizan contra la nube mediante una cola de sincronización tolerante a fallos y reintentos.
Ninguna función crítica de venta puede depender de una llamada de red síncrona.

### III. Aislamiento de datos por empresa

Cada empresa cliente tiene su propia base de datos SQL Server independiente. La API determina,
en cada petición autenticada, a qué base de datos conectar según la empresa del usuario. No se
comparte una única base de datos multi-tenant entre empresas.

### IV. Respeto al modelo de datos heredado

El esquema de datos parte del de Descartes 1.0 (`db/script.sql`, 206 tablas) y se reutiliza tal
cual: mismos nombres de tabla y campo. Cualquier cambio de estructura (renombrar, eliminar,
romper compatibilidad) requiere justificación explícita documentada en el spec correspondiente
antes de implementarse. Los módulos de hotel (Habitaciones, Reservas, TarjetasHotel, MiniBar)
se mantienen en el esquema pero no se desarrolla funcionalidad sobre ellos en esta fase.

### V. Cumplimiento fiscal español (Veri*Factu y TicketBAI)

El módulo de Facturación DEBE mantener y adaptar la implementación ya existente de Veri*Factu
(hash encadenado, registros inalterables, código QR, conservación mínima 4 años) y de TicketBAI
(País Vasco, tablas `TicketSI`), reutilizando el diseño de Descartes 1.0. Ningún cambio en
Facturación puede romper la trazabilidad o la integridad de los registros ya emitidos.

### VI. Roles y permisos configurables

Los roles de usuario NO están fijados en código. Se crean y editan desde el módulo de
Mantenimiento, y determinan qué módulos/acciones puede ver o ejecutar cada usuario. Todo nuevo
módulo debe integrarse con este sistema de permisos desde su diseño, no añadirse después.

### VII. Backend ligero y explícito

La API se construye en PHP nativo / Slim. Se evitan frameworks pesados y capas de abstracción
innecesarias. Cada endpoint debe ser explícito y documentado; se prefiere código directo y
legible sobre "magia" de framework.

### VIII. Frontend simple y con propósito

Gestión usa Vue 3 + Vite, con componentes reutilizables para los mantenimientos (tablas,
formularios, listados). Venta usa Electron con acceso nativo a periféricos (impresora de
tickets ESC/POS, impresora de etiquetas, lector de código de barras HID, báscula/visor por
serie o USB). No se introducen dependencias de UI sin justificación.

### IX. Desarrollo local primero, nube después

El desarrollo se realiza en local (XAMPP para PHP, SQL Server Developer/Express local). La
arquitectura NO debe asumir un proveedor de nube concreto: la futura migración a Azure, AWS o
un VPS debe ser solo un cambio de configuración de conexión, nunca de arquitectura o de código
de aplicación.

## Restricciones adicionales

- Idioma de negocio y de datos: español (nombres de campos, mensajes al usuario).
- Mercado principal: centros de jardinería (Garden); casos secundarios: bares y pastelerías
  (estos últimos requieren venta por peso vía báscula/visor conectado al TPV).
- Escala esperada: múltiples empresas, algunas con varias tiendas cada una.

## Gobernanza

Esta constitución prevalece sobre cualquier otra práctica o convención dentro del proyecto.
Toda especificación (`spec.md`) y plan (`plan.md`) generado con spec-kit debe verificarse contra
estos principios antes de pasar a `/speckit.tasks`. Cambios en esta constitución incrementan la
versión siguiendo semver (MAJOR: eliminación o redefinición incompatible de un principio; MINOR:
principio nuevo o expansión material; PATCH: aclaraciones menores) y deben registrar un Sync
Impact Report al inicio del archivo.

**Version**: 1.0.0 | **Ratified**: 2026-07-08 | **Last Amended**: 2026-07-08
