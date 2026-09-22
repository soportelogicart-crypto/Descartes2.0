-- 015-albaranes-periodicos-activo: pausar una base periodica sin perder su configuracion.
-- Activo = 0 deja la base con su periodicidad y su fecha base, pero Gen.Alb la omite.
-- Idempotente.

IF OBJECT_ID('dbo.AlbaranesPeriodicos', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.AlbaranesPeriodicos', 'Activo') IS NULL
BEGIN
    -- DEFAULT sin nombre: DF_AlbaranesPeriodicos_* puede estar ocupado en el esquema unificado.
    ALTER TABLE [dbo].[AlbaranesPeriodicos]
        ADD [Activo] bit NOT NULL DEFAULT ((1));
END
GO

-- Las bases pausadas con el apaño anterior (Periodicidad = 0) pasan a inactivas.
IF COL_LENGTH('dbo.AlbaranesPeriodicos', 'Activo') IS NOT NULL
BEGIN
    EXEC('UPDATE [dbo].[AlbaranesPeriodicos]
             SET [Activo] = 0
           WHERE [Periodicidad] <= 0 AND [Activo] = 1');
END
GO
