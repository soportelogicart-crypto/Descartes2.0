-- Descartes 2.0 - plantillas de documentos (albaran, facturas, etc.)
-- Persistencia central en SQL Server (no ficheros locales tipo RPT).

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'DocumentoPlantillas' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE [dbo].[DocumentoPlantillas] (
        [Id]            int IDENTITY(1,1) NOT NULL,
        [EmpresaCodigo] nvarchar(3)   NOT NULL CONSTRAINT DF_DocumentoPlantillas_Empresa DEFAULT (N''),
        [Tipo]          nvarchar(40)  NOT NULL,
        [Nombre]        nvarchar(100) NOT NULL,
        [Descripcion]   nvarchar(250) NULL,
        [Version]       int           NOT NULL CONSTRAINT DF_DocumentoPlantillas_Version DEFAULT (1),
        [Activa]        bit           NOT NULL CONSTRAINT DF_DocumentoPlantillas_Activa DEFAULT (0),
        [Definicion]    nvarchar(max) NOT NULL,
        [Creado]        datetime      NOT NULL CONSTRAINT DF_DocumentoPlantillas_Creado DEFAULT (GETDATE()),
        [Actualizado]   datetime      NOT NULL CONSTRAINT DF_DocumentoPlantillas_Actualizado DEFAULT (GETDATE()),
        CONSTRAINT [PK_DocumentoPlantillas] PRIMARY KEY CLUSTERED ([Id])
    );

    CREATE INDEX [IX_DocumentoPlantillas_Empresa_Tipo]
        ON [dbo].[DocumentoPlantillas] ([EmpresaCodigo], [Tipo], [Activa]);
END
GO
