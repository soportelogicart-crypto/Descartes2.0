-- Liquidación semestral de fidelización: un vale por cliente y periodo.
-- Idempotente.

IF OBJECT_ID('dbo.FidelizacionLiquidaciones', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[FidelizacionLiquidaciones] (
        [Empresa] nvarchar(4) NOT NULL,
        [PeriodoInicio] date NOT NULL,
        [PeriodoFin] date NOT NULL,
        [FechaGeneracion] datetime NOT NULL,
        [ValesEmitidos] int NOT NULL CONSTRAINT [DF_FidLiq_Vales] DEFAULT ((0)),
        [ImporteTotal] float NOT NULL CONSTRAINT [DF_FidLiq_Importe] DEFAULT ((0)),
        [FormaPago] nvarchar(2) NULL,
        CONSTRAINT [PK_FidelizacionLiquidaciones] PRIMARY KEY CLUSTERED (
            [Empresa] ASC, [PeriodoInicio] ASC, [PeriodoFin] ASC
        )
    );
END
GO

IF COL_LENGTH('dbo.Vales', 'TipoVale') IS NULL
    ALTER TABLE dbo.Vales ADD TipoVale nvarchar(20) NULL;
GO
IF COL_LENGTH('dbo.Vales', 'ImporteOriginal') IS NULL
    ALTER TABLE dbo.Vales ADD ImporteOriginal float NULL;
GO
IF COL_LENGTH('dbo.Vales', 'SaldoPendiente') IS NULL
    ALTER TABLE dbo.Vales ADD SaldoPendiente float NULL;
GO

UPDATE dbo.Vales
SET TipoVale = ISNULL(NULLIF(RTRIM(TipoVale), ''), 'REGALO'),
    ImporteOriginal = ISNULL(ImporteOriginal, Importe),
    SaldoPendiente = CASE
        WHEN Liquidado = 1 THEN 0
        ELSE ISNULL(SaldoPendiente, Importe)
    END
WHERE TipoVale IS NULL OR RTRIM(TipoVale) = ''
   OR ImporteOriginal IS NULL OR SaldoPendiente IS NULL;
GO

IF COL_LENGTH('dbo.AlbaranesVentasCab', 'ImporteDtoFidelizacion') IS NULL
    ALTER TABLE dbo.AlbaranesVentasCab
        ADD ImporteDtoFidelizacion float NOT NULL
            CONSTRAINT DF_AlbVenCab_DtoFidelizacion DEFAULT ((0));
GO

IF OBJECT_ID('dbo.ValeConsumos', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ValeConsumos (
        Id bigint IDENTITY(1,1) NOT NULL,
        Empresa nvarchar(4) NOT NULL,
        ValeCodigo int NOT NULL,
        EmpresaVenta nvarchar(4) NOT NULL,
        TipoVenta nvarchar(2) NOT NULL,
        Albaran int NOT NULL,
        Importe float NOT NULL,
        Fecha datetime NOT NULL CONSTRAINT DF_ValeConsumos_Fecha DEFAULT (GETDATE()),
        CONSTRAINT PK_ValeConsumos PRIMARY KEY CLUSTERED (Id),
        CONSTRAINT UQ_ValeConsumos_Documento UNIQUE
            (Empresa, ValeCodigo, EmpresaVenta, TipoVenta, Albaran)
    );
END
GO
