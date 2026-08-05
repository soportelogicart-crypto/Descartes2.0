-- Descartes 2.0 - config de equipo (empresa + puesto por PC)
-- Equivalente web del INI local del programa legacy

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'ConfigEquipo' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE [dbo].[ConfigEquipo] (
        [EquipoId]      nvarchar(50)  NOT NULL,
        [EmpresaCodigo] nvarchar(3)   NOT NULL,
        [PuestoCodigo]  nvarchar(2)   NOT NULL,
        [Actualizado]   datetime      NOT NULL CONSTRAINT DF_ConfigEquipo_Actualizado DEFAULT (GETDATE()),
        CONSTRAINT [PK_ConfigEquipo] PRIMARY KEY CLUSTERED ([EquipoId])
    );
END
GO
