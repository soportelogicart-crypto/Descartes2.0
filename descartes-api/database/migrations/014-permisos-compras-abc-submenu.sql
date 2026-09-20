-- Submenús ABC compras (dimensión). Idempotente. Herencia desde compras-abc si no hay fila.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

DECLARE @mods TABLE (Modulo NVARCHAR(64));
INSERT INTO @mods (Modulo) VALUES
  (N'compras-abc'),
  (N'compras-abc-macrofamilias'),
  (N'compras-abc-subfamilias'),
  (N'compras-abc-familias'),
  (N'compras-abc-articulos'),
  (N'compras-abc-agrupaciones'),
  (N'compras-abc-proveedores'),
  (N'compras-abc-secciones'),
  (N'compras-abc-subsecciones'),
  (N'compras-abc-almacenes');

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT 'ADMIN', m.Modulo, 1, 1, 1, 1
FROM @mods m
WHERE NOT EXISTS (
  SELECT 1 FROM [dbo].[RolPermisos] rp
  WHERE rp.[Rol] = 'ADMIN' AND rp.[Modulo] = m.Modulo
);
GO
