-- 004-compras-gestion (T005): asegurar permiso módulo `compras` en rol ADMIN.
-- Idempotente: no duplica filas. Ya sembrado en 001-mantenimiento-extensiones.sql;
-- esta migración cubre BD donde ADMIN exista o faltaba el módulo.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

IF NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos]
    WHERE [Rol] = 'ADMIN' AND [Modulo] = N'compras'
)
BEGIN
    INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
    VALUES ('ADMIN', N'compras', 1, 1, 1, 1);
END
GO
