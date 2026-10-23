-- 021-actividades-centro-coste: centro de coste opcional en Actividades.
-- Idempotente (SQL Server).

IF OBJECT_ID('dbo.Actividades', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Actividades', 'CodigoCentroCoste') IS NULL
BEGIN
    ALTER TABLE [dbo].[Actividades]
        ADD [CodigoCentroCoste] nvarchar(10) NULL DEFAULT ('');
END
GO

-- ========== MySQL (hosting / esquema unificado MySQL) ==========
-- ALTER TABLE `Actividades` ADD COLUMN `CodigoCentroCoste` varchar(10) NULL DEFAULT '';
