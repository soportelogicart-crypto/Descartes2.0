# Sync XAMPP (T012)

Si Apache sirve `C:\xampp\htdocs\descartes-api\`, copiar tras cambios en API:

- `public/index.php`
- `src/Routes/ventas.php`
- `src/Controllers/VentasController.php`
- `src/Services/Ventas/*`
- `src/Services/RolService.php` (modulo `ventas-abc`)
- `src/bootstrap.php`
- `src/Config/entities.php` (formas-pago: cobroDeArqueo / cobroPago)

El monorepo fuente es `c:\descartes-2.0\descartes-api\`.

Ventas escritura: `POST .../impreso`, `PUT` con Fpago1/2 + ImpFpago1/2.
