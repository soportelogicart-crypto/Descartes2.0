-- 023-articulos-registro-sanitario: texto del registro fitosanitario en ficha de articulo.
-- Idempotente (SQL Server).

IF OBJECT_ID('dbo.Articulos', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Articulos', 'RegistroSanitario') IS NULL
BEGIN
    ALTER TABLE [dbo].[Articulos]
        ADD [RegistroSanitario] nvarchar(25) NULL;
END
GO

-- ========== MySQL (hosting / esquema unificado MySQL) ==========
-- ALTER TABLE `Articulos` ADD COLUMN `RegistroSanitario` varchar(25) NULL DEFAULT '';
