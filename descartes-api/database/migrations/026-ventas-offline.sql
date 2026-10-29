-- 026-ventas-offline: tickets cobrados sin conexión en el TPV.
-- Uuid = clave de idempotencia: un reintento devuelve el mismo ticket.
-- Idempotente (SQL Server). En MySQL la crea OfflineVentaService.

IF OBJECT_ID('dbo.VentasOffline', 'U') IS NULL
BEGIN
    CREATE TABLE [dbo].[VentasOffline] (
        [Uuid] nvarchar(36) NOT NULL,
        [Empresa] nvarchar(3) NOT NULL,
        [Puesto] nvarchar(10) NOT NULL,
        [NumeroOffline] nvarchar(30) NOT NULL,
        [Albaran] int NOT NULL CONSTRAINT [DF_VentasOffline_Albaran] DEFAULT (0),
        [Ticket] int NOT NULL CONSTRAINT [DF_VentasOffline_Ticket] DEFAULT (0),
        [Estado] nvarchar(1) NOT NULL,
        [Importe] decimal(18,2) NOT NULL CONSTRAINT [DF_VentasOffline_Importe] DEFAULT (0),
        [Recibido] datetime NOT NULL,
        [Procesado] datetime NULL,
        [Incidencias] nvarchar(2000) NULL,
        [Error] nvarchar(1000) NULL,
        CONSTRAINT [PK_VentasOffline] PRIMARY KEY CLUSTERED ([Uuid] ASC)
    );
    CREATE INDEX [IX_VentasOffline_Puesto] ON [dbo].[VentasOffline]([Empresa], [Puesto], [NumeroOffline]);
END
GO
