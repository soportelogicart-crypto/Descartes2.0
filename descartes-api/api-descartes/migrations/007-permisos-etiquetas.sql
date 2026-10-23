-- 005-etiquetas-gestion (T005): asegurar permiso módulo `etiquetas` en rol ADMIN.
-- Idempotente: no duplica filas.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

IF NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos]
    WHERE [Rol] = 'ADMIN' AND [Modulo] = N'etiquetas'
)
BEGIN
    INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
    VALUES ('ADMIN', N'etiquetas', 1, 1, 1, 1);
END
GO
