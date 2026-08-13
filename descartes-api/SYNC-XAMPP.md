# Sync XAMPP

Si Apache sirve `C:\xampp\htdocs\descartes-api\`, tras cambios en la API:

```powershell
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1
```

Equivalente: `descartes-api\scripts\sync-xampp.cmd`.

Copia en UTF-8 sin BOM todo `src\` y `public\` (mantenimiento, ventas, facturación, compras, **etiquetas**, etc.).

El monorepo fuente es `c:\descartes-2.0\descartes-api\`.

## Compras (004)

Tras tocar rutas/servicios/controllers de compras (`Routes/compras.php`, `Controllers/ComprasController.php`, `Services/Compras/…`), ejecutar el sync antes de probar contra XAMPP.

También aplica la migración de permisos si hace falta (ruta desde la raíz del monorepo):

`db/migrations/006-permisos-compras.sql` (ADMIN + módulo `compras`).

## Etiquetas (005)

Tras tocar rutas/servicios/controllers de etiquetas (`Routes/etiquetas.php`, `Controllers/EtiquetasController.php`, `Services/Etiquetas/…`), ejecutar el sync antes de probar contra XAMPP.

### Permisos ADMIN

Si el menú Etiquetas no aparece o el ping responde 403:

```text
db/migrations/007-permisos-etiquetas.sql
```

(idempotente; semilla `etiquetas` ver/crear/editar/eliminar en rol ADMIN).

Tras aplicar, **cerrar sesión y volver a entrar** (o refrescar `/api/auth/me`) para recargar permisos.

### Formato etiqueta por puesto (multi-tamaño)

Varias plantillas `tipo=etiqueta` (distinto mm). Default del puesto:

```text
db/migrations/008-puestos-formato-etiquetas.sql
```

Columna `Puestos.FormatoEtiquetas` (nombre de plantilla). UI: Puestos → Generales II → «Etiquetas artículo» (impresora + plantilla).

### Smoke API (tras sync + permisos)

Con sesión autenticada (cookie):

- `GET /api/etiquetas/ping` → `{ ok: true, modulo: "etiquetas", … }`
- `GET /api/etiquetas` → listado paginado de la cola

Frontend (`descartes-gestion`) no usa este script: se sirve con Vite en desarrollo. Ruta UI: `/etiquetas` (requiere `etiquetas.ver`).
