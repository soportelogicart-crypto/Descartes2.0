-- Permisos por informe del catálogo de listados (hijos de `listados`).
-- Idempotente. Roles con `listados` ya heredan estos módulos vía MODULO_PADRE si no hay fila propia.

IF NOT EXISTS (SELECT 1 FROM [dbo].[Roles] WHERE [Codigo] = 'ADMIN')
    INSERT INTO [dbo].[Roles] ([Codigo], [Nombre], [Baja]) VALUES ('ADMIN', 'Administrador', 0);
GO

DECLARE @mods TABLE (Modulo NVARCHAR(64));
INSERT INTO @mods (Modulo) VALUES
  (N'listados-informe-tickets'),
  (N'listados-extracto-clientes'),
  (N'listados-stock'),
  (N'listados-stock-minimos'),
  (N'listados-informe-iva');

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT 'ADMIN', m.Modulo, 1, 1, 1, 1
FROM @mods m
WHERE NOT EXISTS (
  SELECT 1 FROM [dbo].[RolPermisos] rp
  WHERE rp.[Rol] = 'ADMIN' AND rp.[Modulo] = m.Modulo
);
GO
