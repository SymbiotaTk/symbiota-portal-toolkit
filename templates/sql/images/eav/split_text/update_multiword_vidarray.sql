-- Update VidArray for multi-word values using JOIN (set operation, not row-by-row)
UPDATE TempSplitTokens
SET VidArray = lookup.VidArray
FROM TempMultiWordLookup lookup
WHERE TempSplitTokens.ValueText = lookup.ValueText
  AND TempSplitTokens.WordCount > 1
  AND TempSplitTokens.VidArray IS NULL;

