-- 009-listados-gestion: permiso módulo `listados` en rol ADMIN.
-- Idempotente.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

IF NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos]
    WHERE [Rol] = 'ADMIN' AND [Modulo] = N'listados'
)
BEGIN
    INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
    VALUES ('ADMIN', N'listados', 1, 1, 1, 1);
END
GO
