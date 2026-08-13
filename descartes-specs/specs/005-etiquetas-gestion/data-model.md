# Data Model: 005-etiquetas-gestion

**Feature**: Creación e impresión de etiquetas  
**Date**: 2026-08-13  
**Source**: `db/script.sql` + [research.md](./research.md) + [spec.md](./spec.md)

## Principio de mapeo

- API/UI: **camelCase** JSON.
- SQL: nombres legacy.
- `Empresa` = **tienda** (`Empresas.Codigo`).
- Cola de impresión: tabla legacy `EtiquetasArticulo` (sin tabla nueva en MVP).
- Plantillas: ecosistema Documentos 2.0 (tipo `etiqueta`); no depender de `FormatosEtiquetas` para el diseñador.

---

## 1. Línea de cola — `EtiquetasArticulo`

### Tabla

| Rol | Tabla | PK |
|-----|-------|-----|
| Cola | `EtiquetasArticulo` | `(Articulo, NroLin)` — `NroLin` IDENTITY |

### API ↔ SQL

| API | SQL | Notas |
|-----|-----|-------|
| articulo | Articulo | Código artículo |
| nroLin | NroLin | IDENTITY; clave con articulo |
| ean | Ean | float legacy → string dígitos en API |
| cantidad | Etiquetas | **Copias a imprimir** (smallint). Preferir este campo para UI «cantidad». |
| cantidadStock | Cantidad | float opcional (legado); no confundir con copias |
| precio | Precio | Precio impreso en etiqueta |
| precioVentaTeorico | PrecioVentaTeorico | Opcional |
| descripcion | Descripcion | nchar(50); truncar si hace falta |
| lote | Lote | Opcional |
| puesto | Puesto | Puesto que encoló / imprime |
| tipoDocumento | TipoDocumento | p. ej. `'C'` compra, espacio si manual |
| empresa | Empresa | Tienda origen (albarán) |
| albaran | Albaran | Nº albarán compra si aplica |
| fechaDocumento | FechaDocumento | |
| proveedor | Proveedor | Si origen compra |
| fechaOferta | FechaOferta | Opcional |
| genStock | GenStock | Legado; v1 puede ignorar o fijar blank |
| numEti / numEtiEnHoja | NumEti / NumEtiEnHoja | Legado hoja; v1 opcional |
| caraImpresionDobleCara | CaraImpresionDobleCara | Fuera MVP |

### Reglas

- Alta manual / escáner: `cantidad` (Etiquetas) ≥ 1; EAN si la tienda lo exige.
- Impresión OK → **DELETE** de las filas impresas (vaciar cola).
- Generación desde albarán: una fila por línea de artículo; `cantidad` sugerida = `CEIL(ABS(Cantidad línea))` mínimo 1; rellenar `empresa`, `albaran`, `tipoDocumento`, `proveedor`, `fechaDocumento`, `lote` si existe.

---

## 2. Artículo / EAN (lectura)

| Origen | Uso |
|--------|-----|
| `Articulos` | codigo, descripcion, precioVen*, etiquetaPrecio |
| `ArtBarras` | ean, unidades |
| Resolver | `GET /api/mantenimiento/articulos/resolver?q=` |

Precio etiqueta por defecto: `precioVen1` (o tarifa), ajustado si `etiquetasIvaIncluido` en tienda.

---

## 3. Plantilla editable (2.0)

Extiende el modelo de Documentos (no tabla SQL nueva obligatoria si ya se guardan plantillas en BD/repo actual).

| Campo | Notas |
|-------|--------|
| tipo | `'etiqueta'` |
| page.format | `'label'` |
| page.widthMm / heightMm | Tamaño de **esa** plantilla (p. ej. 50×30, 60×40). Varias plantillas = varios formatos/tamaños. |
| blocks | texto, campo, codigo-barras (EAN), precio |

Bloques bind sugeridos: `articulo.codigo`, `articulo.descripcion`, `articulo.ean`, `articulo.precio`.

### Asociación multi-formato (T018)

Misma impresora Windows (`Puestos.ImpresoraEtiquetas`); el **formato** cambia eligiendo plantilla:

1. **Varias plantillas** `tipo=etiqueta` por empresa (distinto diseño y/o mm).
2. **Default puesto**: `Puestos.FormatoEtiquetas` = nombre de la plantilla del diseñador (migración `008-puestos-formato-etiquetas.sql`). UI: Puestos → Generales II → fila «Etiquetas artículo».
3. **Default empresa**: flag `Activa` en `DocumentoPlantillas` (una activa por tipo; sirve si el puesto no tiene formato).
4. **Al imprimir** (cola / ficha): selector con todas las plantillas etiqueta; precarga = puesto → activa → esqueleto `etiqueta-std`. Ver `usePlantillaEtiqueta.ts`.

---

## 4. Puesto / tienda

| API | SQL / origen |
|-----|----------------|
| impresoraEtiquetas | `Puestos.ImpresoraEtiquetas` |
| formatoEtiquetas | `Puestos.FormatoEtiquetas` (nombre plantilla diseñador; default del puesto) |
| impEtiquetasSinEans | `Empresas.ImpEtiquetasSinEans` |
| etiquetasIvaIncluido | `Empresas.EtiquetasIvaIncluido` |
| impEtiquetasSoloEansPropios | `Empresas.ImpEtiquetasSoloEansPropios` |

---

## 5. Permisos

Módulo lógico **`etiquetas`**:

| Acción | Capacidades |
|--------|-------------|
| ver | Menú cola, preview |
| crear | Añadir a cola; generar desde albarán |
| editar | Cambiar cantidad; **imprimir**; impresión rápida ficha artículo |
| eliminar | Quitar líneas de cola |

---

## 6. Endpoints API (resumen)

| Método | Ruta | Acción |
|--------|------|--------|
| GET | `/api/etiquetas` | Listar cola (filtros puesto/empresa) |
| POST | `/api/etiquetas` | Añadir línea |
| PUT | `/api/etiquetas/{articulo}/{nroLin}` | Actualizar (cantidad, ean, precio…) |
| DELETE | `/api/etiquetas/{articulo}/{nroLin}` | Quitar línea |
| POST | `/api/etiquetas/imprimir` | Body: ids o «todas»; imprime + DELETE OK |
| POST | `/api/etiquetas/desde-albaran-compra` | Body: empresa, albaran → encola líneas |
| GET | `/api/etiquetas/preview-datos` | Datos para render plantilla (artículo/línea) |

Impresión física: cliente Electron (`printLabel`); API puede devolver payload HTML/datos o solo confirmar borrado tras OK del cliente (definir en implementación: **preferible** cliente renderiza + imprime + llama DELETE/imprimir-confirmar).

**Recomendación implementación**:

1. API sirve datos de cola + datos de artículo/plantilla.  
2. UI renderiza HTML con plantilla.  
3. Bridge `printLabel({ html, impresora, pageWidthMm, pageHeightMm, copies })`.  
4. Si OK → `POST /imprimir` con lista de `nroLin` a vaciar (o endpoint `DELETE` batch).

---

## State

```
Cola:  [líneas] --imprimir OK--> (vacía esas líneas)
       \--borrar manual-->
Ficha artículo: imprimir N copias (sin pasar por cola, o encolar+imprimir+vaciar en un paso)
Albarán compra: generar --> [líneas en cola]
```
