-- Descartes 2.0: RolPermisos.Modulo necesita > 30 chars
-- (p.ej. facturacion-albaranes-pendientes = 32)

IF COL_LENGTH('dbo.RolPermisos', 'Modulo') IS NOT NULL
BEGIN
    ALTER TABLE [dbo].[RolPermisos] ALTER COLUMN [Modulo] nvarchar(64) NOT NULL;
END
GO
