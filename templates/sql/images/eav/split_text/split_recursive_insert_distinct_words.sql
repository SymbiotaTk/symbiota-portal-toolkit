-- Insert DISTINCT words into ValuesText (elimination technique)
INSERT OR IGNORE INTO cache.ValuesText (ValueText)
SELECT DISTINCT Word
FROM TempSplitWords;

