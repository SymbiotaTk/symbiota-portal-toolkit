-- Update VidArray for single-word values using JOIN (set operation, not row-by-row)
UPDATE TempSplitTokens
SET VidArray = CAST(vt.Vid AS TEXT)
FROM cache.ValuesText vt
WHERE TempSplitTokens.ValueText = vt.ValueText
  AND TempSplitTokens.WordCount = 1
  AND TempSplitTokens.VidArray IS NULL;

