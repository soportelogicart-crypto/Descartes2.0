-- Permite usar un DNI/NIE completo u otro identificador como tarjeta de fidelización.
-- La columna legacy era nvarchar(8), insuficiente para los 9 caracteres de un DNI.
-- Idempotente.

IF COL_LENGTH('dbo.Clientes', 'TarjetaFidelizacion') IS NOT NULL
   AND EXISTS (
       SELECT 1
       FROM sys.columns
       WHERE object_id = OBJECT_ID('dbo.Clientes')
         AND name = 'TarjetaFidelizacion'
         AND max_length < 40 -- nvarchar(20): 2 bytes por carácter
   )
BEGIN
    ALTER TABLE dbo.Clientes
        ALTER COLUMN TarjetaFidelizacion nvarchar(20) NULL;
END
GO
