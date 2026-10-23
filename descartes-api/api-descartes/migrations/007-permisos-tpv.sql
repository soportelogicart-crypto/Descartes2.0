-- 006-tpv-venta-tactil: permiso módulo `tpv` en rol ADMIN.
-- Idempotente. El módulo `tpv` ya está en catálogo de 001-mantenimiento-extensiones.sql.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

IF NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos]
    WHERE [Rol] = 'ADMIN' AND [Modulo] = N'tpv'
)
BEGIN
    INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
    VALUES ('ADMIN', N'tpv', 1, 1, 1, 1);
END
ELSE
BEGIN
    UPDATE [dbo].[RolPermisos]
    SET [Eliminar] = 1
    WHERE [Rol] = 'ADMIN' AND [Modulo] = N'tpv';
END
GO
