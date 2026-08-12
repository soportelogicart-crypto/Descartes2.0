# Sync XAMPP

Si Apache sirve `C:\xampp\htdocs\descartes-api\`, tras cambios en la API:

```powershell
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1
```

Equivalente: `descartes-api\scripts\sync-xampp.cmd`.

Copia en UTF-8 sin BOM todo `src\` y `public\` (mantenimiento, ventas, facturación, **compras**, etc.).

El monorepo fuente es `c:\descartes-2.0\descartes-api\`.

## Compras (004)

Tras tocar rutas/servicios/controllers de compras (`Routes/compras.php`, `Controllers/ComprasController.php`, `Services/Compras/…`), ejecutar el sync antes de probar contra XAMPP.

También aplica la migración de permisos si hace falta: `db/migrations/006-permisos-compras.sql` (ADMIN + módulo `compras`).

Frontend (`descartes-gestion`) no usa este script: se sirve con Vite en desarrollo.
