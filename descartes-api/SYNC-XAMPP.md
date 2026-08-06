# Sync XAMPP

Si Apache sirve `C:\xampp\htdocs\descartes-api\`, tras cambios en la API:

```powershell
powershell -ExecutionPolicy Bypass -File c:\descartes-2.0\descartes-api\scripts\sync-xampp.ps1
```

Copia en UTF-8 sin BOM todo `src\` y `public\` (mantenimiento, ventas, facturación, etc.).

El monorepo fuente es `c:\descartes-2.0\descartes-api\`.
