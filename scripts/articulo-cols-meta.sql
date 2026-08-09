SET NOCOUNT ON;
SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME='Articulos'
  AND COLUMN_NAME IN (
    'LUpdate','FechaAlta','PesoEnKilos','idWeb','Alternativo','UnidadStock','Imagen',
    'CodigoB2B','CodIvaAgrario','CodigoIntrastat','Siglas','Sectorial','Estado','Inventario',
    'Seccion','SubSeccion','Categoria','NombreBotanico','Floracion','Exposicion','Poda',
    'UnidadEmpaquetado','TipoPlanta','LiteralPlanta1','LiteralPlanta2','Patron','Variedad',
    'Altura','RegistroSanitario','ObservacionesVenta','Empaquetado','UnidadesCompra'
  )
ORDER BY COLUMN_NAME;

SELECT RTRIM(Codigo) C, FechaAlta, LUpdate, PesoEnKilos, CONVERT(varchar(50), idWeb) idWeb
FROM Articulos WHERE RTRIM(Codigo) IN ('0010032962','0020032041');
