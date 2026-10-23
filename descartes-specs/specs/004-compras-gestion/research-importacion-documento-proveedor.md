# Research: importación documento proveedor (OCR / IA)

**Estado**: estudio (Fase 3 del plan arrastre albarán compras)  
**Relacionado**: [spec.md](./spec.md) § Estudio importación automática

## Objetivo

Reducir la digitación de albaranes de compra cuando el proveedor entrega PDF escaneado o foto, manteniendo **revisión humana obligatoria** antes de guardar y actualizar stock.

## Implementado antes de OCR (Fases 0–2)

| Fase | Entrega |
|------|---------|
| 0 | Zona arrastre + visor PDF/imagen + sugerencia «Su albarán» |
| 1 | CSV + `POST /api/compras/albaranes/import-preview` |
| 2 | PDF digital + plantillas `config/import-proveedores/{proveedor}.json` |

## Opciones OCR / IA evaluadas (resumen)

| Proveedor | Pros | Contras |
|-----------|------|---------|
| Azure Document Intelligence | Tablas y facturas, API estable | Coste por página, datos en Microsoft |
| Google Document AI | Buen OCR | Misma privacidad / coste |
| OpenAI Vision / GPT-4o | Flexible en formatos raros | Coste, variabilidad, compliance |
| Self-hosted (Tesseract + reglas) | Sin nube | Mantenimiento, peor en escaneos malos |

**Encaje Descartes 2.0**: la API PHP en servidor propio puede llamar HTTPS a un servicio nube; no requiere Node en el hosting web. Electron puede enviar el fichero solo tras confirmación del usuario.

## Riesgos

- Códigos proveedor ≠ código interno → tabla de equivalencias o revisión línea a línea.
- Escaneos de baja calidad → errores de cantidad/precio.
- Datos fiscales del proveedor en terceros → acuerdo DPA / opt-in por cliente.

## Decisión recomendada (go/no-go)

- **Go parcial**: seguir con CSV + PDF digital + plantillas por proveedor (ya en producción).
- **Go OCR**: cuando haya ≥ N albaranes/mes escaneados del mismo proveedor y plantilla regex no alcance; piloto con un proveedor y Azure DI o similar.
- **No-go** grabación automática sin pantalla de revisión.

## Entregables si se abre implementación OCR

1. Endpoint `POST /api/compras/albaranes/import-preview-ocr` (async opcional).
2. UI: misma modal de revisión que import-preview.
3. Config `.env`: claves API, límite de páginas.
4. Métricas: % líneas corregidas en revisión, tiempo medio por albarán.

## Privacidad

- No enviar a la nube sin aviso en UI.
- Preferir procesamiento local en Electron para el binario; enviar solo texto extraído si es posible.
