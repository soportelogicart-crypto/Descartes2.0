-- 005-etiquetas-gestion (T018): plantilla/formato por defecto de etiquetas de artículo en el puesto.
-- Varias plantillas tipo `etiqueta` (distinto tamaño mm) coexisten; este campo = default del puesto.
-- Idempotente.

IF COL_LENGTH('dbo.Puestos', 'FormatoEtiquetas') IS NULL
BEGIN
    ALTER TABLE [dbo].[Puestos] ADD [FormatoEtiquetas] nvarchar(100) NULL;
END
GO
