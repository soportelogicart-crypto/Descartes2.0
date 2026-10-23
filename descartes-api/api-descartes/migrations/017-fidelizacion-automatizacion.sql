-- Programa semestral seleccionable y trazabilidad del puesto que genera los vales.
-- Idempotente.

IF NOT EXISTS (
    SELECT 1
    FROM dbo.TiposCalculoFidelizacion
    WHERE RTRIM(Codigo) = 'VALE_SEMESTRAL'
)
BEGIN
    INSERT INTO dbo.TiposCalculoFidelizacion
        (Codigo, Nombre, Motor, Factor, Configuracion, Baja)
    VALUES
        (
            'VALE_SEMESTRAL',
            N'Vale semestral (1 punto por euro)',
            'VALE_SEMESTRAL',
            1,
            N'{"pjeCanje":3,"mesesCaducidad":6}',
            0
        );
END
GO

UPDATE dbo.Empresas_Ges
SET TipoCalculoFidelizacion = 'VALE_SEMESTRAL'
WHERE RTRIM(ISNULL(TipoCalculoFidelizacion, '')) = '';
GO

IF COL_LENGTH('dbo.FidelizacionLiquidaciones', 'PuestoGeneracion') IS NULL
    ALTER TABLE dbo.FidelizacionLiquidaciones ADD PuestoGeneracion nvarchar(20) NULL;
GO

IF COL_LENGTH('dbo.FidelizacionLiquidaciones', 'GeneracionAutomatica') IS NULL
    ALTER TABLE dbo.FidelizacionLiquidaciones
        ADD GeneracionAutomatica bit NOT NULL
            CONSTRAINT DF_FidLiq_Automatica DEFAULT ((0));
GO

IF COL_LENGTH('dbo.AlbaranesVentasCab', 'PuntosFidelizacionCompra') IS NULL
    ALTER TABLE dbo.AlbaranesVentasCab
        ADD PuntosFidelizacionCompra float NOT NULL
            CONSTRAINT DF_AlbVenCab_PuntosFidCompra DEFAULT ((0));
GO

IF COL_LENGTH('dbo.AlbaranesVentasCab', 'PuntosFidelizacionAcumulados') IS NULL
    ALTER TABLE dbo.AlbaranesVentasCab
        ADD PuntosFidelizacionAcumulados float NOT NULL
            CONSTRAINT DF_AlbVenCab_PuntosFidAcum DEFAULT ((0));
GO
