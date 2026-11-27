-- Permiso del informe de clientes (hijo de `listados`).
-- Idempotente. Roles con `listados` ya lo heredan vía MODULO_PADRE si no hay fila propia.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT 'ADMIN', N'listados-clientes', 1, 1, 1, 1
WHERE NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos] rp
    WHERE rp.[Rol] = 'ADMIN' AND rp.[Modulo] = N'listados-clientes'
);
GO
