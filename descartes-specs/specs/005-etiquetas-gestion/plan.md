# Implementation Plan: 005-etiquetas-gestion

**Branch**: `005-etiquetas-gestion`  
**Spec**: [spec.md](./spec.md) · **Research**: [research.md](./research.md) · **Data**: [data-model.md](./data-model.md) · **Tasks**: [tasks.md](./tasks.md)  
**Date**: 2026-08-13  
**Status**: Ready for implementation (T001+)

## Summary

MVP Gestión: menú **Etiquetas** (cola + cantidad + imprimir y vaciar) + botón Artículo (impresión rápida) + plantillas editables tipo etiqueta + generar desde **albarán de compra**. Impresión HTML → Electron `printLabel` → `ImpresoraEtiquetas`.

## Phases

1. Setup + permisos/nav  
2. Cola CRUD (US2)  
3. Plantillas tipo etiqueta (US3)  
4. Impresión + preview + vaciar (US2/US4)  
5. Impresión rápida ficha (US1)  
6. Desde albarán compra (US5)  
7. Polish  

## Artifacts

- [x] spec.md (decisiones + US5 en MVP)  
- [x] research.md  
- [x] data-model.md  
- [x] contracts/etiquetas-api.openapi.yaml (esqueleto)  
- [x] tasks.md (T001–T037)  
- [ ] quickstart.md (T036)  

## Constitution check

- Permisos (VI) · Cola legacy (IV) · Gestión online · Sin Veri*Factu  
