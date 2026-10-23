-- Submenús: listado stock (agrupación) y ABC ventas (dimensión).
-- Idempotente. Herencia desde listados-stock / ventas-abc si no hay fila.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

DECLARE @mods TABLE (Modulo NVARCHAR(64));
INSERT INTO @mods (Modulo) VALUES
  (N'listados-stock-macrofamilia'),
  (N'listados-stock-subfamilia'),
  (N'listados-stock-familia'),
  (N'listados-stock-articulo'),
  (N'listados-stock-agrupacion'),
  (N'listados-stock-proveedor'),
  (N'ventas-abc-dias-semana'),
  (N'ventas-abc-semanal'),
  (N'ventas-abc-horas'),
  (N'ventas-abc-macrofamilias'),
  (N'ventas-abc-subfamilias'),
  (N'ventas-abc-familias'),
  (N'ventas-abc-articulos'),
  (N'ventas-abc-agrupaciones'),
  (N'ventas-abc-clientes'),
  (N'ventas-abc-proveedores'),
  (N'ventas-abc-secciones'),
  (N'ventas-abc-subsecciones'),
  (N'ventas-abc-perfiles'),
  (N'ventas-abc-vendedores');

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT 'ADMIN', m.Modulo, 1, 1, 1, 1
FROM @mods m
WHERE NOT EXISTS (
  SELECT 1 FROM [dbo].[RolPermisos] rp
  WHERE rp.[Rol] = 'ADMIN' AND rp.[Modulo] = m.Modulo
);
GO
