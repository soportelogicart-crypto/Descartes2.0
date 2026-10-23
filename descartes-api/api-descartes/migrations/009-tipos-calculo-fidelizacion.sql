-- 007-fidelizacion-clientes: catálogo extensible de métodos y selección por tienda.
-- Idempotente. Vacío en Empresas_Ges = no acumular.

IF OBJECT_ID('dbo.TiposCalculoFidelizacion', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[TiposCalculoFidelizacion] (
        [Codigo] nvarchar(20) NOT NULL,
        [Nombre] nvarchar(50) NOT NULL,
        [Motor] nvarchar(30) NOT NULL,
        [Factor] float NOT NULL
            CONSTRAINT [DF_TiposCalculoFidelizacion_Factor] DEFAULT ((1)),
        [Configuracion] nvarchar(max) NULL,
        [Baja] bit NOT NULL
            CONSTRAINT [DF_TiposCalculoFidelizacion_Baja] DEFAULT ((0)),
        CONSTRAINT [PK_TiposCalculoFidelizacion] PRIMARY KEY CLUSTERED ([Codigo] ASC)
    );
END
GO

IF COL_LENGTH('dbo.Empresas_Ges', 'TipoCalculoFidelizacion') IS NULL
BEGIN
    -- DEFAULT sin nombre: DF_Empresas_* puede estar ocupado en el esquema unificado.
    ALTER TABLE [dbo].[Empresas_Ges]
        ADD [TipoCalculoFidelizacion] nvarchar(20) NULL DEFAULT ('');
END
GO

IF NOT EXISTS (
    SELECT 1 FROM [dbo].[TiposCalculoFidelizacion] WHERE RTRIM([Codigo]) = 'EUROS'
)
BEGIN
    INSERT INTO [dbo].[TiposCalculoFidelizacion] ([Codigo], [Nombre], [Motor], [Factor], [Baja])
    VALUES ('EUROS', N'Saldo en euros (%)', 'EUROS', 1, 0);
END
GO

IF NOT EXISTS (
    SELECT 1 FROM [dbo].[TiposCalculoFidelizacion] WHERE RTRIM([Codigo]) = 'PUNTOS'
)
BEGIN
    INSERT INTO [dbo].[TiposCalculoFidelizacion] ([Codigo], [Nombre], [Motor], [Factor], [Baja])
    VALUES ('PUNTOS', N'Puntos (1 / €)', 'PUNTOS', 1, 0);
END
GO
