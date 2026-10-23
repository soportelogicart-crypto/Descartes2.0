-- Situación de ventas: mismo acceso que el módulo Ventas en los roles que ya lo tienen.
-- Idempotente.

INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT rp.[Rol], N'ventas-situacion', rp.[Ver], rp.[Crear], rp.[Editar], rp.[Eliminar]
FROM [dbo].[RolPermisos] rp
WHERE rp.[Modulo] = N'ventas'
  AND NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos] x
    WHERE x.[Rol] = rp.[Rol] AND x.[Modulo] = N'ventas-situacion'
  );
GO
