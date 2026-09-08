SET NOCOUNT ON;
IF OBJECT_ID('tempdb..#cmp') IS NOT NULL DROP TABLE #cmp;
CREATE TABLE #cmp (col sysname, legacy nvarchar(4000), d20 nvarchar(4000), legacy_null bit, d20_null bit);

DECLARE @sql nvarchar(max) = N'';
SELECT @sql = @sql + N'
INSERT INTO #cmp(col, legacy, d20, legacy_null, d20_null)
SELECT ''' + COLUMN_NAME + ''',
  CAST(l.[' + COLUMN_NAME + '] AS nvarchar(4000)),
  CAST(n.[' + COLUMN_NAME + '] AS nvarchar(4000)),
  CASE WHEN l.[' + COLUMN_NAME + '] IS NULL THEN 1 ELSE 0 END,
  CASE WHEN n.[' + COLUMN_NAME + '] IS NULL THEN 1 ELSE 0 END
FROM (SELECT * FROM Empresas_Ges WHERE RTRIM(Codigo)=''3'') l
CROSS JOIN (SELECT * FROM Empresas_Ges WHERE RTRIM(Codigo)=''4'') n
WHERE (l.[' + COLUMN_NAME + '] IS NULL) <> (n.[' + COLUMN_NAME + '] IS NULL)
   OR (l.[' + COLUMN_NAME + '] IS NOT NULL AND n.[' + COLUMN_NAME + '] IS NOT NULL
       AND CAST(l.[' + COLUMN_NAME + '] AS nvarchar(4000)) <> CAST(n.[' + COLUMN_NAME + '] AS nvarchar(4000)));
'
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_NAME='Empresas_Ges' AND DATA_TYPE NOT IN ('timestamp') AND COLUMN_NAME <> 'upsize_ts';

EXEC sp_executesql @sql;

SELECT col, legacy, d20, legacy_null, d20_null
FROM #cmp
WHERE d20_null = 1 AND legacy_null = 0
ORDER BY col;

SELECT '--- other diffs ---' AS sep;
SELECT col, legacy, d20, legacy_null, d20_null
FROM #cmp
WHERE NOT (d20_null = 1 AND legacy_null = 0)
ORDER BY col;
