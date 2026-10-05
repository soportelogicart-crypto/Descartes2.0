# Pruebas PDF por familia de listados

Fecha de la última ejecución automática: **01/10/2026**.

Este documento separa dos comprobaciones:

- **PDF con datos controlados:** repetible, no necesita sesión ni base de datos.
- **Validación real y papel:** requiere entrar en Gestión, generar con datos del cliente y revisar una impresión física.

Los PDF controlados están en `Detalles/listados/qa-pdf/`. Son muestras de QA, no documentos contables ni patrones para comparar byte a byte.

## Ejecución automática

Requisitos:

1. API local disponible.
2. Vite en `http://127.0.0.1:5173`.
3. Dependencias de `descartes-electron` instaladas.

Comando:

```powershell
cd C:\descartes-2.0\descartes-electron
npm run qa:listados-pdf
```

El script `scripts/generar-listados-pdf-qa.cjs`:

1. Importa desde Vite los generadores usados por producción.
2. Construye muestras con textos largos, números, totales y suficientes filas para probar paginación.
3. Decide la orientación con la misma lógica que Gestión.
4. Comprueba en el DOM si alguna tabla desborda horizontalmente.
5. Genera los PDF con Electron/Chromium.
6. Guarda `resultado.json` con orientación, número de tablas y desbordamientos.

La ejecución falla con código 2 si detecta alguna tabla desbordada.

## Resultado automático del 01/10/2026

### Stock

- Archivo: `01-stock.pdf`.
- Resultado: **correcto**.
- Una página vertical.
- Cabecera, 4 columnas y total final visibles.
- Tablas desbordadas: 0.

Prueba real pendiente:

- Generar por artículo y por familia.
- Comparar unidades y número de artículos con la pantalla.
- Imprimir una página y confirmar tamaño de letra.

### Stock bajo mínimos

- Archivo: `02-stock-minimos.pdf`.
- Resultado: **correcto**.
- Una página vertical con 8 columnas.
- Descripción y valores de stock legibles.
- Tablas desbordadas: 0.

Prueba real pendiente:

- Probar un almacén concreto y todos los almacenes.
- Confirmar Stock, Mínimo, Óptimo y Faltan contra la pantalla.

### Extracto de clientes

- Archivo: `03-extracto-clientes.pdf`.
- Resultado: **correcto**.
- Una página vertical con 7 columnas.
- Debe, Haber y Saldo alineados y con pie de totales.
- Tablas desbordadas: 0.

Prueba real pendiente:

- Elegir un cliente con saldo anterior, ventas y cobros.
- Comprobar que saldo inicial + movimientos = saldo final.
- Confirmar que las dos fechas son obligatorias.

### Informe de IVA

- Archivo: `04-informe-iva.pdf`.
- Resultado HTML: **correcto**.
- Una página horizontal con varios tipos de IVA.
- La orientación automática cambia a apaisado.
- Tablas desbordadas: 0.

Pruebas reales pendientes:

- Comparar Base, Cuota y Total con la pantalla.
- Probar un periodo con un tipo de IVA y otro con varios tipos.
- Abrir también **Vista previa PDF**, porque ese botón usa el PDF generado por la API y no el HTML de esta prueba.
- Verificar que el PDF de la API y la impresión HTML tienen los mismos totales.

### Listados de mantenimiento

- Archivo: `05-mantenimiento-clientes.pdf`.
- Resultado: **correcto**.
- Dos páginas verticales con 9 columnas y textos largos.
- La cabecera de tabla se repite en la segunda página.
- Tablas desbordadas: 0.

Pruebas reales pendientes:

- Clientes: filtrar una columna y comprobar que solo salen las filas visibles.
- Artículos: repetir con el grid ancho y comprobar si activa apaisado.
- Macrofamilias: comprobar un maestro corto en vertical.
- Confirmar que cero filas no abre la vista previa.

### ABC de ventas

- Archivos:
  - `06-abc-ventas-articulos.pdf`.
  - `07-abc-ventas-dias-semana.pdf`.
- Resultado: **correcto**.
- Artículos: tabla completa, totales visibles y sin desbordamiento.
- Días de la semana: tabla y gráfico SVG visibles.
- Tablas desbordadas: 0.

Pruebas reales pendientes:

- Artículos: comparar total general con la pantalla.
- Horas: comprobar matriz y gráfico con todas las franjas.
- Clientes: comprobar agrupación y saltos de página.
- Días de la semana: comparar visualmente con `Detalles/listados/legacy.pdf`.

### ABC de compras

- Archivo: `08-abc-compras-familias.pdf`.
- Resultado: **correcto**.
- Ocho páginas verticales.
- En modo desglosado el gráfico sale primero y cada familia empieza en una página nueva.
- La descripción conserva ancho suficiente.
- Tablas desbordadas: 0.

Pruebas reales pendientes:

- Familias con `Im. artículos = Sí`: listado continuo.
- Familias con `Im. artículos = Desglosado`: salto por familia.
- Artículos: confirmar que no aparece M.Agr.
- Comparar unidades, importe y total general con la pantalla.

### Informe de tickets

- Archivos:
  - `09-tickets-desglosado.pdf` — dos páginas.
  - `10-tickets-resumido.pdf` — una página.
  - `11-tickets-super-resumido.pdf` — una página.
  - `12-tickets-diario-iva.pdf` — dos páginas.
- Resultado: **correcto**.
- Todos permanecen en A4 vertical.
- Cabecera, rango, formas de pago, perfil, IVA y totales caben en página.
- Tablas desbordadas: 0.
- El PDF de referencia numera las páginas. Chromium devuelve `0` con
  `counter(page)`, por lo que Gestión no muestra un número falso; queda como
  diferencia conocida hasta disponer de un paginador compatible.

Pruebas reales pendientes:

- Generar los cuatro formatos con la misma sesión.
- Comparar visualmente con `Detalles/informe-tickets/`.
- Confirmar el logo de empresa.
- Confirmar que primer/último ticket y formas de pago no se cortan.
- Buscar solo por sesión, sin fechas, y comprobar el rango calculado.

## Comprobaciones transversales pendientes en papel

Hacer al menos una prueba vertical y una horizontal:

1. Microsoft Print to PDF.
2. Impresora A4 configurada como **Listados** en el puesto.
3. Primera y última página de un informe multipágina.
4. Cabecera de tabla repetida después del salto.
5. Colores legibles en escala de grises.
6. Ninguna fila o total cortado entre páginas.
7. Márgenes completos, especialmente el borde derecho.
8. La impresora térmica de tickets no recibe listados A4.

Registrar debajo la fecha, equipo, impresora y resultado:

- [ ] PDF vertical con datos reales.
- [ ] PDF horizontal con datos reales.
- [ ] Papel vertical.
- [ ] Papel horizontal.
- [ ] Informe multipágina.
- [ ] Comparación de totales pantalla/PDF.

## Criterio de cierre

La prueba automática detecta regresiones de composición y orientación, pero no valida datos de negocio ni controladores de impresora. El paso queda cerrado cuando:

- Los 12 PDF controlados se regeneran sin desbordamientos.
- Cada familia tiene al menos una prueba con datos reales.
- Se verifica una salida física vertical y otra horizontal.
- Los totales de pantalla y PDF coinciden.
