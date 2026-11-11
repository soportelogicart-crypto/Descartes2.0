-- 028-articulos-lleva-sobre: articulos que generan encargo (sobre + etiqueta) y
-- impresora/plantilla de etiquetas de encargo por puesto. Idempotente (SQL Server).

IF OBJECT_ID('dbo.Articulos', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Articulos', 'LlevaSobre') IS NULL
BEGIN
    ALTER TABLE [dbo].[Articulos] ADD [LlevaSobre] bit NULL DEFAULT 0;
END
GO

IF OBJECT_ID('dbo.Articulos', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Articulos', 'SobresPorUnidad') IS NULL
BEGIN
    ALTER TABLE [dbo].[Articulos] ADD [SobresPorUnidad] int NULL DEFAULT 1;
END
GO

IF OBJECT_ID('dbo.Puestos', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Puestos', 'ImpresoraEtiquetasEncargo') IS NULL
BEGIN
    ALTER TABLE [dbo].[Puestos] ADD [ImpresoraEtiquetasEncargo] nvarchar(100) NULL;
END
GO

IF OBJECT_ID('dbo.Puestos', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Puestos', 'FormatoEtiquetasEncargo') IS NULL
BEGIN
    ALTER TABLE [dbo].[Puestos] ADD [FormatoEtiquetasEncargo] nvarchar(100) NULL;
END
GO

-- ========== MySQL (hosting / esquema unificado MySQL) ==========
-- ALTER TABLE `Articulos` ADD COLUMN `LlevaSobre` tinyint NULL DEFAULT 0;
-- ALTER TABLE `Articulos` ADD COLUMN `SobresPorUnidad` int NULL DEFAULT 1;
-- ALTER TABLE `Puestos` ADD COLUMN `ImpresoraEtiquetasEncargo` varchar(100) NULL;
-- ALTER TABLE `Puestos` ADD COLUMN `FormatoEtiquetasEncargo` varchar(100) NULL;
