-- Modulos de permiso granulares por submenu de Mantenimiento (nvarchar(30)).
DECLARE @modulos TABLE (Modulo nvarchar(30));
INSERT INTO @modulos VALUES
    ('macrofamilias'), ('familias'), ('subfamilias'), ('agrupaciones'),
    ('secciones'), ('subsecciones'),
    ('actividades'), ('intereses-comerciales'), ('oferta-clientes'), ('campanas'),
    ('oferta-proveedores'),
    ('puestos-parametros'), ('puestos-trabajo');

-- ADMIN: hereda de padre si existe, si no todos a 1.
INSERT INTO [dbo].[RolPermisos] ([Rol], [Modulo], [Ver], [Crear], [Editar], [Eliminar])
SELECT
    'ADMIN',
    m.Modulo,
    COALESCE(padre.[Ver], CAST(1 AS bit)),
    COALESCE(padre.[Crear], CAST(1 AS bit)),
    COALESCE(padre.[Editar], CAST(1 AS bit)),
    COALESCE(padre.[Eliminar], CAST(1 AS bit))
FROM @modulos m
OUTER APPLY (
    SELECT TOP 1 rp.[Ver], rp.[Crear], rp.[Editar], rp.[Eliminar]
    FROM [dbo].[RolPermisos] rp
    WHERE rp.[Rol] = 'ADMIN'
      AND rp.[Modulo] = CASE
            WHEN m.Modulo IN ('macrofamilias','familias','subfamilias','agrupaciones','secciones','subsecciones') THEN 'articulos'
            WHEN m.Modulo IN ('actividades','intereses-comerciales','oferta-clientes','campanas') THEN 'clientes'
            WHEN m.Modulo = 'oferta-proveedores' THEN 'proveedores'
            WHEN m.Modulo IN ('puestos-parametros','puestos-trabajo') THEN 'puestos'
            ELSE NULL
          END
) padre
WHERE NOT EXISTS (
    SELECT 1 FROM [dbo].[RolPermisos] rp
    WHERE rp.[Rol] = 'ADMIN' AND rp.[Modulo] = m.Modulo
);
GO
