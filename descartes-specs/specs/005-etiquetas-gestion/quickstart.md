# Quickstart: 005-etiquetas-gestion

**Feature**: Creación e impresión de etiquetas (Gestión)  
**Contrato**: [contracts/etiquetas-api.openapi.yaml](./contracts/etiquetas-api.openapi.yaml)  
**Modelo**: [data-model.md](./data-model.md)

## Prerequisites

- Stack como en [001 quickstart](../001-mantenimiento-gestion/quickstart.md) (XAMPP + SQL + Vite).
- Tras cambios PHP: `descartes-api/scripts/sync-xampp.ps1` — ver [SYNC-XAMPP.md](../../../descartes-api/SYNC-XAMPP.md).
- Login dev: `ADM` / `admin123`.
- Migraciones (si faltan columnas/permisos):
  - `descartes-api/db/migrations/007-permisos-etiquetas.sql`
  - `descartes-api/db/migrations/008-puestos-formato-etiquetas.sql` (`Puestos.FormatoEtiquetas`)
- **Electron** para impresión física (`printLabel`). En navegador: preview + diálogo del sistema.

## Permisos

Módulo lógico **`etiquetas`** (Roles → matriz):

| Acción | UI / API |
|--------|----------|
| `ver` | Menú Etiquetas, cola, preview |
| `crear` | Alta en cola; **Generar etiquetas** desde albarán compra |
| `editar` | Cambiar copias; **imprimir** (cola y ficha artículo) |
| `eliminar` | Quitar línea de cola |

Sin `etiquetas.ver` → ruta `/etiquetas` denegada.

## Puesto e impresora

En **Puestos de trabajo → Generales II → Etiquetas artículo**:

| Campo UI | SQL | Uso |
|----------|-----|-----|
| Impresora | `Puestos.ImpresoraEtiquetas` | Destino Electron `printLabel` |
| Formato | `Puestos.FormatoEtiquetas` | Nombre plantilla default (diseñador tipo `etiqueta`) |

Sin impresora configurada, la UI bloquea la impresión con mensaje claro.

## Plantillas

- Configuración → Documentos → plantillas tipo **`etiqueta`** (`widthMm` × `heightMm`).
- Multi-formato: varias plantillas, misma impresora; selector al imprimir.
- Default: formato del puesto → plantilla `activa` de la empresa → esqueleto `etiqueta-std`.

## Flags tienda (`Empresas`)

| Flag | Efecto |
|------|--------|
| `ImpEtiquetasSinEans` | Si off, no se imprime/genera sin EAN |
| `ImpEtiquetasSoloEansPropios` | Solo EAN de ArtBarras del artículo |
| `EtiquetasIvaIncluido` | Reservado (precio PVP en v1) |

## Arranque rápido

```powershell
# API
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1

# Frontend
cd c:\descartes-2.0\descartes-gestion
npm run dev

# Electron (impresión real) — desde descartes-electron
npm start
```

Abrir la shell Electron (o `http://localhost:5173`) → login → menú **Etiquetas**.

Ping:

```powershell
curl -X POST http://localhost/descartes-api/public/api/auth/login `
  -H "Content-Type: application/json" `
  -d '{"usuario":"ADM","password":"admin123"}' -c cookies.txt

curl http://localhost/descartes-api/public/api/etiquetas/ping -b cookies.txt
```

## Flujos UI

### Cola (US2)

1. Etiquetas → añadir por código/EAN/escáner (copias).
2. Editar cantidad; Preview; Imprimir selección/todas.
3. Tras OK → líneas eliminadas de cola.

### Ficha artículo (US1)

1. Mantenimiento → Artículos → ficha → **Etiquetas**.
2. Elegir EAN, copias (≥1), formato → Preview → Imprimir.
3. No usa la cola.

### Albarán compra (US5)

1. Compras → Albarán (guardado) → **Generar etiquetas**.
2. Mensaje con creadas/omitidas → **Ver cola** o menú Etiquetas → Imprimir.

## Smokes (API)

Desde `descartes-api`:

```powershell
php scripts/smoke-etiquetas-cola.php       # T014 SC-cola
php scripts/smoke-etiquetas-imprimir.php   # T025 SC-002
php scripts/smoke-etiquetas-rapida.php     # T029 SC-001 prerrequisitos
php scripts/smoke-etiquetas-albaran.php    # T033 SC-006
```

## Electron `printLabel`

- Implementación real en `descartes-electron/electron/peripherals.js` (`stub: false`).
- Payload: `html`, `pageWidthMm`, `pageHeightMm`, `copies`, `impresora`.
- Tras cambiar Electron: **reiniciar** la app para cargar el bridge.

## Checklist demo

- [ ] Permiso `etiquetas` en rol ADMIN
- [ ] Puesto con `ImpresoraEtiquetas` (p. ej. METO)
- [ ] ≥1 plantilla tipo etiqueta (o esqueleto)
- [ ] Cola: 3 líneas → Preview → Imprimir → vacía
- [ ] Artículo con EAN → 2 copias → impresora
- [ ] Albarán ≥2 líneas → Generar → cola ≥2
