-- 024-asientos-lin-centro-coste: centro de coste en lineas de asiento (traspaso facturas).
-- Idempotente (SQL Server).

IF OBJECT_ID('dbo.AsientosLin', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.AsientosLin', 'CentroCoste') IS NULL
BEGIN
    ALTER TABLE [dbo].[AsientosLin]
        ADD [CentroCoste] nvarchar(10) NULL DEFAULT ('');
END
GO

-- ========== MySQL (hosting / esquema unificado MySQL) ==========
-- ALTER TABLE `AsientosLin` ADD COLUMN `CentroCoste` varchar(10) NULL DEFAULT '';
