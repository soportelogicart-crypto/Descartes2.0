# Sync XAMPP

Hay **dos copias** en XAMPP para poder trabajar en local sin romper las pruebas del otro PC:

| Carpeta | Quién la usa | Comando |
|---|---|---|
| `C:\xampp\htdocs\descartes-api-dev` | Tú (Vite / `npm run dev`) | `sync-xampp.ps1` (por defecto) |
| `C:\xampp\htdocs\descartes-api` | Otro PC (hosting → puerto 9080) | `sync-xampp.ps1 -Pruebas` |

```powershell
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1 -Pruebas
```

Equivalente: `descartes-api\scripts\sync-xampp.cmd` (añade `-Pruebas` para publicar al otro PC).

La UI de pruebas no cambia hasta que vuelvas a generar `descartes-gestion` (`npm run build`) y subas `dist` al hosting.

Copia en UTF-8 sin BOM todo `src\`, `public\` y `database\migrations\` (migraciones SQL incluidas en el despliegue).

El monorepo fuente es `c:\descartes-2.0\descartes-api\`.

## Instalación / otra base de datos

No hace falta ejecutar `sqlcmd` a mano en cada cliente:

1. Abrir Gestión → asistente **Configuración de la instalación** (`/instalacion`) o **Configuración → Base de datos**.
2. Indicar servidor, **nombre de la BD**, usuario y contraseña → **Guardar y actualizar estructura**.

La API guarda `var/instalacion.json` (prioridad sobre `.env`) y aplica las migraciones pendientes registradas en `SchemaMigrations`.

CLI equivalente (misma config activa):

```powershell
php c:\descartes-2.0\descartes-api\scripts\migrate.php
```

Opcional en desarrollo: `AUTO_MIGRATE=true` en `.env` aplica pendientes al conectar.

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
