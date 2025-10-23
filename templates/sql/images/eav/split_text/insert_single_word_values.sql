-- Insert DISTINCT single-word values into ValuesText
INSERT OR IGNORE INTO cache.ValuesText (ValueText)
SELECT DISTINCT ValueText 
FROM TempSplitTokens 
WHERE WordCount = 1;

