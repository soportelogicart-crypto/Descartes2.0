-- 022-actividades-drop-descripcion-centro-coste: descripcion del centro solo en CentrosCoste.
-- Idempotente (SQL Server).

IF OBJECT_ID('dbo.Actividades', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Actividades', 'DescripcionCentroCoste') IS NOT NULL
BEGIN
    ALTER TABLE [dbo].[Actividades] DROP COLUMN [DescripcionCentroCoste];
END
GO

-- MySQL: ActividadesCentroCosteSchema::eliminarDescripcionCentroCosteSiExiste()
