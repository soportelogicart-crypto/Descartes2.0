-- 025-club-fidelizacion-saldo: motor CLUB_SALDO (lotes, vales, promos caja).
-- Idempotente (SQL Server).

IF OBJECT_ID('dbo.FidelizacionSaldoLotes', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[FidelizacionSaldoLotes] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [Cliente] nvarchar(9) NOT NULL,
        [Empresa] nvarchar(3) NOT NULL,
        [ImporteCentimos] int NOT NULL,
        [SaldoCentimosRestante] int NOT NULL,
        [GeneradoEn] datetime NOT NULL CONSTRAINT [DF_FidSaldoLotes_GeneradoEn] DEFAULT (GETDATE()),
        [CaducaEn] datetime NOT NULL,
        [TipoDocOrigen] nvarchar(1) NULL,
        [AlbaranOrigen] int NULL,
        CONSTRAINT [PK_FidelizacionSaldoLotes] PRIMARY KEY CLUSTERED ([Id] ASC)
    );
    CREATE INDEX [IX_FidSaldoLotes_Cliente_Caduca] ON [dbo].[FidelizacionSaldoLotes]([Cliente], [CaducaEn]);
END
GO

IF OBJECT_ID('dbo.FidelizacionSaldoMovimientos', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[FidelizacionSaldoMovimientos] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [LoteId] int NULL,
        [Cliente] nvarchar(9) NOT NULL,
        [TipoMov] nvarchar(20) NOT NULL,
        [Centimos] int NOT NULL,
        [Empresa] nvarchar(3) NOT NULL,
        [TipoDoc] nvarchar(1) NULL,
        [Albaran] int NULL,
        [CreadoEn] datetime NOT NULL CONSTRAINT [DF_FidSaldoMov_CreadoEn] DEFAULT (GETDATE()),
        CONSTRAINT [PK_FidelizacionSaldoMovimientos] PRIMARY KEY CLUSTERED ([Id] ASC)
    );
END
GO

IF OBJECT_ID('dbo.FidelizacionValesClub', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[FidelizacionValesClub] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [Cliente] nvarchar(9) NOT NULL,
        [Empresa] nvarchar(3) NOT NULL,
        [Tipo] nvarchar(20) NOT NULL,
        [ImporteCentimos] int NOT NULL,
        [MinCompraCentimos] int NOT NULL,
        [ValidoDesde] datetime NOT NULL,
        [ValidoHasta] datetime NOT NULL,
        [Usado] bit NOT NULL CONSTRAINT [DF_FidValesClub_Usado] DEFAULT ((0)),
        [TipoDocUso] nvarchar(1) NULL,
        [AlbaranUso] int NULL,
        CONSTRAINT [PK_FidelizacionValesClub] PRIMARY KEY CLUSTERED ([Id] ASC)
    );
    CREATE INDEX [IX_FidValesClub_Cliente_Activo] ON [dbo].[FidelizacionValesClub]([Cliente], [Usado], [ValidoHasta]);
END
GO

IF OBJECT_ID('dbo.ClubPromociones', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[ClubPromociones] (
        [Id] int IDENTITY(1,1) NOT NULL,
        [Empresa] nvarchar(3) NOT NULL,
        [Nombre] nvarchar(80) NOT NULL,
        [PjeDescuento] float NOT NULL,
        [FechaInicio] datetime NOT NULL,
        [FechaFin] datetime NOT NULL,
        [FiltrosJson] nvarchar(max) NULL,
        [Activa] bit NOT NULL CONSTRAINT [DF_ClubPromo_Activa] DEFAULT ((1)),
        CONSTRAINT [PK_ClubPromociones] PRIMARY KEY CLUSTERED ([Id] ASC)
    );
END
GO

IF COL_LENGTH('dbo.Clientes', 'FechaAltaClub') IS NULL
    ALTER TABLE [dbo].[Clientes] ADD [FechaAltaClub] datetime NULL;
GO

IF COL_LENGTH('dbo.Clientes', 'ConsentimientoEmailClub') IS NULL
    ALTER TABLE [dbo].[Clientes] ADD [ConsentimientoEmailClub] bit NOT NULL
        CONSTRAINT [DF_Clientes_ConsentEmailClub] DEFAULT ((0));
GO

IF COL_LENGTH('dbo.Clientes', 'ConsentimientoWhatsAppClub') IS NULL
    ALTER TABLE [dbo].[Clientes] ADD [ConsentimientoWhatsAppClub] bit NOT NULL
        CONSTRAINT [DF_Clientes_ConsentWaClub] DEFAULT ((0));
GO

IF COL_LENGTH('dbo.Clientes', 'ConsentimientoClubEn') IS NULL
    ALTER TABLE [dbo].[Clientes] ADD [ConsentimientoClubEn] datetime NULL;
GO

IF COL_LENGTH('dbo.AlbaranesVentasCab', 'ClubSaldoGeneradoCentimos') IS NULL
    ALTER TABLE [dbo].[AlbaranesVentasCab] ADD [ClubSaldoGeneradoCentimos] int NOT NULL
        CONSTRAINT [DF_AVCab_ClubGen] DEFAULT ((0));
GO

IF COL_LENGTH('dbo.AlbaranesVentasCab', 'ClubSaldoCanjeadoCentimos') IS NULL
    ALTER TABLE [dbo].[AlbaranesVentasCab] ADD [ClubSaldoCanjeadoCentimos] int NOT NULL
        CONSTRAINT [DF_AVCab_ClubCanje] DEFAULT ((0));
GO

IF COL_LENGTH('dbo.AlbaranesVentasCab', 'ClubPromoId') IS NULL
    ALTER TABLE [dbo].[AlbaranesVentasCab] ADD [ClubPromoId] int NULL;
GO

IF COL_LENGTH('dbo.AlbaranesVentasCab', 'ClubModoIncompatible') IS NULL
    ALTER TABLE [dbo].[AlbaranesVentasCab] ADD [ClubModoIncompatible] bit NOT NULL
        CONSTRAINT [DF_AVCab_ClubIncompat] DEFAULT ((0));
GO

IF NOT EXISTS (
    SELECT 1 FROM [dbo].[TiposCalculoFidelizacion] WHERE RTRIM([Codigo]) = 'CLUB_CA_AGUSTI'
)
BEGIN
    INSERT INTO [dbo].[TiposCalculoFidelizacion] ([Codigo], [Nombre], [Motor], [Factor], [Configuracion], [Baja])
    VALUES (
        'CLUB_CA_AGUSTI',
        N'Club Ca l''Agustí (saldo 2,5%)',
        'CLUB_SALDO',
        1,
        N'{"pjeAcumulacion":2.5,"caducidadMeses":12,"minSaldoCanjeVisibleEuros":1,"bienvenida":{"importe":5,"minCompraElegible":30,"diasValidez":30},"cumpleanos":{"importe":5,"minCompraElegible":30,"diasAltaMinimos":30},"promoIncompatiblePct":10,"exclusiones":[]}',
        0
    );
END
GO
