-- 030-usuarios-idioma-color: idioma, color de base y ayudas del menu de cada
-- usuario de gestion. Los guarda el propio usuario desde el menu o desde su ficha.
-- Idioma: ca | es | en (vacio = castellano). ColorTema: id del tema (vacio = azul).
-- AyudasMenu: 1 activadas, 0 desactivadas (vacio = activadas).
-- Idempotente (SQL Server). En runtime tambien lo asegura MejorasGestionSchema.

IF OBJECT_ID('dbo.Usuarios_Ges', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Usuarios_Ges', 'Idioma') IS NULL
BEGIN
    ALTER TABLE [dbo].[Usuarios_Ges] ADD [Idioma] nvarchar(5) NULL;
END
GO

IF OBJECT_ID('dbo.Usuarios_Ges', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Usuarios_Ges', 'ColorTema') IS NULL
BEGIN
    ALTER TABLE [dbo].[Usuarios_Ges] ADD [ColorTema] nvarchar(20) NULL;
END
GO

IF OBJECT_ID('dbo.Usuarios_Ges', 'U') IS NOT NULL
    AND COL_LENGTH('dbo.Usuarios_Ges', 'AyudasMenu') IS NULL
BEGIN
    ALTER TABLE [dbo].[Usuarios_Ges] ADD [AyudasMenu] bit NULL;
END
GO

-- ========== MySQL (local / hosting / esquema unificado MySQL) ==========
-- ALTER TABLE `Usuarios_Ges` ADD COLUMN `Idioma` varchar(5) NULL;
-- ALTER TABLE `Usuarios_Ges` ADD COLUMN `ColorTema` varchar(20) NULL;
-- ALTER TABLE `Usuarios_Ges` ADD COLUMN `AyudasMenu` tinyint NULL DEFAULT 1;
