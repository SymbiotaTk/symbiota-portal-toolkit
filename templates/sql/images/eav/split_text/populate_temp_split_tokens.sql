-- Populate temp table with values from Records
-- Calculate WordCount using LENGTH difference (count spaces + 1)
INSERT INTO TempSplitTokens (Eid, Aid, ValueText, WordCount, VidArray)
SELECT 
  Eid,
  {aid} AS Aid,
  {column_name} AS ValueText,
  LENGTH({column_name}) - LENGTH(REPLACE({column_name}, ' ', '')) + 1 AS WordCount,
  NULL AS VidArray
FROM Records
WHERE {column_name} IS NOT NULL AND {column_name} != '';

