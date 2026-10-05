-- Correo SMTP del usuario que envía pedidos, albaranes y facturas.

IF NOT EXISTS (SELECT 1 FROM sys.tables WHERE name = 'UsuarioCorreo' AND schema_id = SCHEMA_ID('dbo'))
BEGIN
    CREATE TABLE [dbo].[UsuarioCorreo] (
        [Usuario]          nvarchar(20)  NOT NULL,
        [Activo]           bit           NOT NULL CONSTRAINT DF_UsuarioCorreo_Activo DEFAULT (0),
        [Servidor]         nvarchar(200) NOT NULL CONSTRAINT DF_UsuarioCorreo_Servidor DEFAULT (''),
        [Puerto]           int           NOT NULL CONSTRAINT DF_UsuarioCorreo_Puerto DEFAULT (25),
        [Ssl]              bit           NOT NULL CONSTRAINT DF_UsuarioCorreo_Ssl DEFAULT (0),
        [StartTls]         bit           NOT NULL CONSTRAINT DF_UsuarioCorreo_StartTls DEFAULT (0),
        [UsuarioSmtp]      nvarchar(200) NOT NULL CONSTRAINT DF_UsuarioCorreo_UsuarioSmtp DEFAULT (''),
        [Clave]            nvarchar(200) NOT NULL CONSTRAINT DF_UsuarioCorreo_Clave DEFAULT (''),
        [NombreRemitente]  nvarchar(200) NOT NULL CONSTRAINT DF_UsuarioCorreo_Nombre DEFAULT (''),
        [EmailRemitente]   nvarchar(200) NOT NULL CONSTRAINT DF_UsuarioCorreo_Email DEFAULT (''),
        [Copia]            nvarchar(200) NOT NULL CONSTRAINT DF_UsuarioCorreo_Copia DEFAULT (''),
        [Actualizado]      datetime      NOT NULL CONSTRAINT DF_UsuarioCorreo_Actualizado DEFAULT (GETDATE()),
        CONSTRAINT [PK_UsuarioCorreo] PRIMARY KEY CLUSTERED ([Usuario])
    );
END
GO
