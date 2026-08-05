-- Descartes 2.0 - extensiones Mantenimiento (001-mantenimiento-gestion)
-- SIN tabla Tiendas: tiendas = [Empresas] legacy

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'Roles' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE [dbo].[Roles] (
        [Codigo]   nvarchar(10)  NOT NULL,
        [Nombre]   nvarchar(50)  NOT NULL,
        [Baja]     bit           NOT NULL CONSTRAINT DF_Roles_Baja DEFAULT (0),
        CONSTRAINT [PK_Roles] PRIMARY KEY CLUSTERED ([Codigo])
    );
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'RolPermisos' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE [dbo].[RolPermisos] (
        [Rol]      nvarchar(10) NOT NULL,
        [Modulo]   nvarchar(30) NOT NULL,
        [Ver]      bit          NOT NULL CONSTRAINT DF_RolPermisos_Ver DEFAULT (0),
        [Crear]    bit          NOT NULL CONSTRAINT DF_RolPermisos_Crear DEFAULT (0),
        [Editar]   bit          NOT NULL CONSTRAINT DF_RolPermisos_Editar DEFAULT (0),
        [Eliminar] bit          NOT NULL CONSTRAINT DF_RolPermisos_Eliminar DEFAULT (0),
        CONSTRAINT [PK_RolPermisos] PRIMARY KEY CLUSTERED ([Rol], [Modulo]),
        CONSTRAINT [FK_RolPermisos_Roles] FOREIGN KEY ([Rol]) REFERENCES [dbo].[Roles]([Codigo])
    );
END
GO

IF COL_LENGTH('dbo.Usuarios', 'Rol') IS NULL
    ALTER TABLE [dbo].[Usuarios] ADD [Rol] nvarchar(10) NULL;
GO

IF COL_LENGTH('dbo.Usuarios', 'Baja') IS NULL
    ALTER TABLE [dbo].[Usuarios] ADD [Baja] bit NOT NULL CONSTRAINT DF_Usuarios_Baja DEFAULT (0);
GO

IF COL_LENGTH('dbo.Empresas', 'Baja') IS NULL
    ALTER TABLE [dbo].[Empresas] ADD [Baja] bit NOT NULL CONSTRAINT DF_Empresas_Baja DEFAULT (0);
GO

IF COL_LENGTH('dbo.Proveedores', 'Baja') IS NULL
    ALTER TABLE [dbo].[Proveedores] ADD [Baja] bit NOT NULL CONSTRAINT DF_Proveedores_Baja DEFAULT (0);
GO

IF COL_LENGTH('dbo.Almacenes', 'Baja') IS NULL
    ALTER TABLE [dbo].[Almacenes] ADD [Baja] bit NOT NULL CONSTRAINT DF_Almacenes_Baja DEFAULT (0);
GO

IF COL_LENGTH('dbo.Puestos', 'Baja') IS NULL
    ALTER TABLE [dbo].[Puestos] ADD [Baja] bit NOT NULL CONSTRAINT DF_Puestos_Baja DEFAULT (0);
GO

IF COL_LENGTH('dbo.Puestos', 'Trabajador') IS NULL
    ALTER TABLE [dbo].[Puestos] ADD [Trabajador] nvarchar(4) NULL;
GO

IF COL_LENGTH('dbo.Puestos', 'Usuario') IS NULL
    ALTER TABLE [dbo].[Puestos] ADD [Usuario] nvarchar(6) NULL;
GO

IF COL_LENGTH('dbo.Vendedores', 'Baja') IS NULL
    ALTER TABLE [dbo].[Vendedores] ADD [Baja] bit NOT NULL CONSTRAINT DF_Vendedores_Baja DEFAULT (0);
GO

IF COL_LENGTH('dbo.Impuestos', 'Baja') IS NULL
    ALTER TABLE [dbo].[Impuestos] ADD [Baja] bit NOT NULL CONSTRAINT DF_Impuestos_Baja DEFAULT (0);
GO

IF COL_LENGTH('dbo.FormasPago', 'Baja') IS NULL
    ALTER TABLE [dbo].[FormasPago] ADD [Baja] bit NOT NULL CONSTRAINT DF_FormasPago_Baja DEFAULT (0);
GO

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

DECLARE @modulos TABLE (Modulo nvarchar(30));
INSERT INTO @modulos VALUES
    ('mantenimiento'), ('empresas'), ('tiendas'), ('usuarios'), ('roles'),
    ('trabajadores'), ('puestos'), ('articulos'), ('clientes'), ('proveedores'),
    ('almacenes'), ('impuestos'), ('formas-pago'), ('compras'), ('ventas'),
    ('facturacion'), ('inventario'), ('tpv');

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT 'ADMIN', m.Modulo, 1, 1, 1, 1
FROM @modulos m
WHERE NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos] rp WHERE rp.[Rol] = 'ADMIN' AND rp.[Modulo] = m.Modulo
);
GO

UPDATE [dbo].[Usuarios]
SET [Rol] = 'ADMIN'
WHERE [Codigo] = 'ADMIN' AND ([Rol] IS NULL OR [Rol] = '');
GO

IF COL_LENGTH('dbo.Usuarios', 'PassWord') IS NOT NULL
BEGIN
    ALTER TABLE [dbo].[Usuarios] ALTER COLUMN [PassWord] nvarchar(255) NULL;
END
GO
