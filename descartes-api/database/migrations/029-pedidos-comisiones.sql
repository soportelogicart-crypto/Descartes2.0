-- 029-pedidos-comisiones: comisión opcional de proveedor (importe fijo) y dos
-- comisiones de trabajador (porcentaje sobre el importe del pedido).
-- Idempotente (SQL Server).

IF OBJECT_ID('dbo.PedidosClientes', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.PedidosClientes', 'ProveedorComision') IS NULL
BEGIN
    ALTER TABLE [dbo].[PedidosClientes] ADD [ProveedorComision] nvarchar(15) NULL;
END
GO

IF OBJECT_ID('dbo.PedidosClientes', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.PedidosClientes', 'ImporteComisionProveedor') IS NULL
BEGIN
    ALTER TABLE [dbo].[PedidosClientes] ADD [ImporteComisionProveedor] decimal(18, 2) NULL;
END
GO

IF OBJECT_ID('dbo.PedidosClientes', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.PedidosClientes', 'TrabajadorComision1') IS NULL
BEGIN
    ALTER TABLE [dbo].[PedidosClientes] ADD [TrabajadorComision1] nvarchar(4) NULL;
END
GO

IF OBJECT_ID('dbo.PedidosClientes', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.PedidosClientes', 'PjeComision1') IS NULL
BEGIN
    ALTER TABLE [dbo].[PedidosClientes] ADD [PjeComision1] decimal(9, 4) NULL;
END
GO

IF OBJECT_ID('dbo.PedidosClientes', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.PedidosClientes', 'TrabajadorComision2') IS NULL
BEGIN
    ALTER TABLE [dbo].[PedidosClientes] ADD [TrabajadorComision2] nvarchar(4) NULL;
END
GO

IF OBJECT_ID('dbo.PedidosClientes', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.PedidosClientes', 'PjeComision2') IS NULL
BEGIN
    ALTER TABLE [dbo].[PedidosClientes] ADD [PjeComision2] decimal(9, 4) NULL;
END
GO

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT rp.[Rol], N'listados-comisiones-proveedor', rp.[Ver], rp.[Crear], rp.[Editar], rp.[Eliminar]
FROM [dbo].[RolPermisos] rp
WHERE rp.[Modulo] = N'listados-detalle-ventas'
  AND NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos] x
    WHERE x.[Rol] = rp.[Rol] AND x.[Modulo] = N'listados-comisiones-proveedor'
  );
GO

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT rp.[Rol], N'listados-comisiones-trabajadores', rp.[Ver], rp.[Crear], rp.[Editar], rp.[Eliminar]
FROM [dbo].[RolPermisos] rp
WHERE rp.[Modulo] = N'listados-detalle-ventas'
  AND NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos] x
    WHERE x.[Rol] = rp.[Rol] AND x.[Modulo] = N'listados-comisiones-trabajadores'
  );
GO

-- ========== MySQL (hosting / esquema unificado MySQL) ==========
-- ALTER TABLE `PedidosClientes` ADD COLUMN `ProveedorComision` varchar(15) NULL;
-- ALTER TABLE `PedidosClientes` ADD COLUMN `ImporteComisionProveedor` decimal(18,2) NULL;
-- ALTER TABLE `PedidosClientes` ADD COLUMN `TrabajadorComision1` varchar(4) NULL;
-- ALTER TABLE `PedidosClientes` ADD COLUMN `PjeComision1` decimal(9,4) NULL;
-- ALTER TABLE `PedidosClientes` ADD COLUMN `TrabajadorComision2` varchar(4) NULL;
-- ALTER TABLE `PedidosClientes` ADD COLUMN `PjeComision2` decimal(9,4) NULL;
